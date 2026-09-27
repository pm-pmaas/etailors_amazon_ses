<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Mailer\Bulk;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Mautic\EmailBundle\Entity\Stat;
use MauticPlugin\AmazonSesBundle\Entity\BulkContent;
use MauticPlugin\AmazonSesBundle\Entity\BulkDelivery;

final class DeliveryStore
{
    /**
     * Deliveries inserted by one statement; the rest of a message follows in further statements of its transaction. A
     * statement also ends once its entries reach BYTES_PER_INSERT: with up to 256 KB of replacement data per recipient,
     * 200 rows could exceed MariaDB's default max_allowed_packet of 16 MB and fail the whole message on every attempt.
     */
    private const ROWS_PER_INSERT = 200;
    private const BYTES_PER_INSERT = 4 * 1024 * 1024;
    /** States no delivery leaves for a submission again; rows in them keep only bookkeeping. */
    private const FINAL_STATES = ['accepted', 'rejected', 'unknown'];
    private const EVENT_RANK = ['' => 0, 'sent' => 1, 'delivered' => 2, 'bounced' => 3, 'rendering_failed' => 3, 'rejected' => 3, 'complained' => 4];

    private string $deliveries;
    private string $contents;
    private bool $ready = false;
    /** @var array<string, true> content ids this instance inserted or found present, reset for every message */
    private array $contentIds = [];

    public function __construct(private EntityManagerInterface $entityManager)
    {
        $this->deliveries = $entityManager->getClassMetadata(BulkDelivery::class)->getTableName();
        $this->contents = $entityManager->getClassMetadata(BulkContent::class)->getTableName();
    }

    /**
     * Shared by install() and the plugin migration.
     *
     * @return string[] CREATE statements for whichever outbox tables, and indexes of existing tables, do not exist yet
     */
    public static function createSchemaSql(EntityManagerInterface $entityManager): array
    {
        $db = $entityManager->getConnection();
        $manager = $db->createSchemaManager();
        $tool = new SchemaTool($entityManager);
        $missing = [];
        $indexes = [];
        foreach ([BulkContent::class, BulkDelivery::class] as $class) {
            $metadata = $entityManager->getClassMetadata($class);
            $table = $metadata->getTableName();
            if (!$manager->tablesExist([$table])) {
                $missing[] = $metadata;
                continue;
            }
            // A table created by an earlier build lacks the indexes added since.
            $existing = $manager->listTableIndexes($table);
            foreach ($tool->getSchemaFromMetadata([$metadata])->getTable($table)->getIndexes() as $index) {
                if (!$index->isPrimary() && !isset($existing[strtolower($index->getName())])) {
                    $indexes[] = $db->getDatabasePlatform()->getCreateIndexSQL($index, $table);
                }
            }
        }

        return [...($missing ? $tool->getCreateSchemaSql($missing) : []), ...$indexes];
    }

    public function install(): void
    {
        $db = $this->entityManager->getConnection();
        foreach (self::createSchemaSql($this->entityManager) as $sql) {
            $db->executeStatement($sql);
        }
        $this->ready = true;
    }

    public function assertInstalled(): void
    {
        if (!$this->ready && !$this->entityManager->getConnection()->createSchemaManager()->tablesExist([$this->contents, $this->deliveries])) {
            throw new \RuntimeException('SES bulk tables are missing. Run bin/console mautic:ses:bulk install before enabling bulk=auto.');
        }
        $this->ready = true;
    }

    /**
     * Ownership survives changes to the Email entity's current headers and template eligibility.
     * Only absent tables mean no ownership; database errors must never permit an untracked raw send.
     *
     * @param list<string> $ids
     *
     * @return array<string, true>
     */
    public function existingIds(array $ids, string $scope): array
    {
        $db = $this->entityManager->getConnection();
        if (!$this->ready && !$db->createSchemaManager()->tablesExist([$this->deliveries])) {
            return [];
        }
        $existing = [];
        foreach (array_chunk($ids, self::ROWS_PER_INSERT) as $chunk) {
            $found = $db->fetchFirstColumn("SELECT id FROM {$this->deliveries} WHERE scope = ? AND id IN (".implode(', ', array_fill(0, count($chunk), '?')).')', [$scope, ...$chunk]);
            foreach ($found as $id) {
                $existing[$id] = true;
            }
        }

        return $existing;
    }

    /** @param list<string> $ids */
    public function dueForIds(array $ids, string $scope): \Generator
    {
        foreach (array_chunk($ids, self::ROWS_PER_INSERT) as $chunk) {
            $rows = array_column($this->entityManager->getConnection()->fetchAllAssociative(
                "SELECT * FROM {$this->deliveries} WHERE scope = ? AND state IN ('pending', 'retry') AND next_attempt <= ? AND attempts < 4 AND id IN (".implode(', ', array_fill(0, count($chunk), '?')).')',
                [$scope, time(), ...$chunk]
            ), null, 'id');
            // Queue replays retain recipient order; cron recovery retains due()'s content ordering.
            foreach ($chunk as $id) {
                if (isset($rows[$id])) {
                    yield $rows[$id];
                }
            }
        }
    }

    /**
     * Saves every delivery of one message in a single transaction before anything is submitted. A failure rolls all of
     * them back, so nothing is left for the retry command while Mautic handles the failed message itself.
     *
     * @param iterable<list<array<string, mixed>>> $batches
     *
     * @return list<list<string>> the delivery ids of each batch
     */
    public function enqueueBatches(iterable $batches, string $scope): array
    {
        $this->assertInstalled();
        $this->contentIds = [];

        return $this->entityManager->getConnection()->transactional(function () use ($batches, $scope): array {
            $ids = [];
            $rows = [];
            $bytes = 0;
            foreach ($batches as $batch) {
                foreach ($batch as $delivery) {
                    $rows[] = $row = $this->deliveryRow($delivery, $scope);
                    $bytes += strlen($row['entry']) + strlen($row['_raw_payload'] ?? '');
                    if (self::ROWS_PER_INSERT === count($rows) || $bytes >= self::BYTES_PER_INSERT) {
                        $this->insertDeliveries($rows);
                        [$rows, $bytes] = [[], 0];
                    }
                }
                $ids[] = array_column($batch, 'id');
            }
            $this->insertDeliveries($rows);

            return $ids;
        });
    }

    /** Save before submission. Existing deliveries are deliberately immutable on queue replay. */
    public function enqueue(array $delivery, string $scope): string
    {
        $this->assertInstalled();
        try {
            $this->entityManager->getConnection()->transactional(function () use ($delivery, $scope): void {
                $this->insertDeliveries([$this->deliveryRow($delivery, $scope)]);
            });
        } catch (\Throwable $e) {
            $this->contentIds = [];
            throw $e;
        }

        return $delivery['id'];
    }

    /** Saves the delivery's content unless this instance already did, and returns the delivery's outbox row. */
    private function deliveryRow(array $delivery, string $scope): array
    {
        $db = $this->entityManager->getConnection();
        $now = time();
        $contentId = hash('sha256', serialize([$scope, $delivery['operation'], $delivery['email_id'], $delivery['common']]));
        // Recipients of one message share content: insert it once per message instead of failing once per recipient.
        if (!isset($this->contentIds[$contentId])) {
            try {
                $db->insert($this->contents, [
                    'id' => $contentId, 'email_id' => $delivery['email_id'], 'scope' => $scope,
                    'operation' => $delivery['operation'], 'payload' => json_encode($delivery['common'], JSON_THROW_ON_ERROR),
                    'requests' => 0, 'request_bytes' => 0, 'created_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Common content is shared across recipients and workers. created_at is the last use, so prune keeps it.
                $db->update($this->contents, ['created_at' => $now], ['id' => $contentId]);
            }
            $this->contentIds[$contentId] = true;
        }

        $row = [
            'id' => $delivery['id'], 'content_id' => $contentId, 'email_id' => $delivery['email_id'],
            'tracking_hash' => $delivery['tracking_hash'], 'scope' => $scope,
            'entry' => json_encode($delivery['entry'], JSON_THROW_ON_ERROR), 'state' => 'pending',
            'event' => '', 'reason' => $delivery['reason'], 'message_id' => '', 'attempts' => 0,
            'next_attempt' => 0, 'updated_at' => $now, 'created_at' => $now, 'claim' => '', 'synced' => 0,
        ];
        if ('raw' === $delivery['operation']) {
            // Internal insert metadata, removed before binding the delivery columns.
            $row['_raw_payload'] = json_encode($delivery['common'], JSON_THROW_ON_ERROR);
        }

        return $row;
    }

    /**
     * A second worker/re-delivered Messenger job must not reset the first outcome, so rows whose id exists are skipped.
     * MySQL/MariaDB and SQLite skip them inside one multi-row statement (INSERT IGNORE would hide other errors too);
     * other platforms insert row by row.
     */
    private function insertDeliveries(array $rows): void
    {
        if (!$rows) {
            return;
        }
        $db = $this->entityManager->getConnection();
        $raw = [];
        foreach ($rows as &$row) {
            if (isset($row['_raw_payload'])) {
                $raw[] = [$row['_raw_payload'], $row['content_id'], $row['id'], $row['scope']];
                unset($row['_raw_payload']);
            }
        }
        unset($row);
        $platform = $db->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $onDuplicate = 'ON DUPLICATE KEY UPDATE id = id';
        } elseif ($platform instanceof SqlitePlatform) {
            $onDuplicate = 'ON CONFLICT(id) DO NOTHING';
        } else {
            foreach ($rows as $row) {
                try {
                    $db->insert($this->deliveries, $row);
                } catch (UniqueConstraintViolationException) {
                    // Exists already and stays as it is.
                }
            }

            $this->restoreRawPayloads($raw);

            return;
        }
        $values = implode(', ', array_fill(0, count($rows), '('.implode(', ', array_fill(0, count($rows[0]), '?')).')'));
        $db->executeStatement(
            "INSERT INTO {$this->deliveries} (".implode(', ', array_keys($rows[0])).") VALUES {$values} {$onDuplicate}",
            array_merge(...array_map('array_values', $rows))
        );
        $this->restoreRawPayloads($raw);
    }

    /**
     * Called inside enqueue's transaction, after the insert has locked each actual delivery (including duplicates).
     * A completion either precedes this check, or waits until restoration commits and then drops the payload itself.
     * The order for enqueue is content, then delivery; completion/event/expiry release their delivery UPDATE's lock
     * before touching content, so they do not hold the reverse pair of locks.
     *
     * @param list<array{string, string, string, string}> $raw
     */
    private function restoreRawPayloads(array $raw): void
    {
        foreach ($raw as [$payload, $contentId, $id, $scope]) {
            $this->entityManager->getConnection()->executeStatement(
                "UPDATE {$this->contents} SET payload = ? WHERE id = ? AND payload = '' AND EXISTS (SELECT 1 FROM {$this->deliveries} d WHERE d.id = ? AND d.scope = ? AND d.content_id = {$this->contents}.id AND d.state IN ('pending', 'retry', 'sending'))",
                [$payload, $contentId, $id, $scope]
            );
        }
    }

    /** Atomic ownership prevents two workers submitting the same delivery. */
    public function claim(string $id, string $scope, string $owner): ?array
    {
        $db = $this->entityManager->getConnection();
        $changed = $db->executeStatement(
            "UPDATE {$this->deliveries} SET state = 'sending', claim = ?, attempts = attempts + 1, updated_at = ? WHERE id = ? AND scope = ? AND state IN ('pending', 'retry') AND next_attempt <= ? AND attempts < 4",
            [$owner, time(), $id, $scope, time()]
        );
        if (!$changed) {
            return null;
        }

        return $db->fetchAssociative("SELECT d.*, c.operation FROM {$this->deliveries} d LEFT JOIN {$this->contents} c ON c.id = d.content_id WHERE d.id = ?", [$id]) ?: null;
    }

    /** @return string the state written, rejected when the last retry was used up */
    public function complete(array $row, string $state, string $reason = '', string $messageId = ''): string
    {
        if ('retry' === $state && (int) $row['attempts'] >= 4) {
            $state = 'rejected';
            $reason = 'retry_exhausted:'.$reason;
        }
        // Accepted on a retry drops the earlier attempt's failure reason. Before the first attempt the reason can only be
        // the raw-fallback reason from enqueue() or a reason from release(), and only the raw-fallback reason stays. A
        // failed attempt already replaced that reason with its own, so a raw-fallback row accepted on a retry ends up with
        // an empty reason. A final state drops the request data, which only a later submission would need.
        $changed = $this->entityManager->getConnection()->executeStatement(
            "UPDATE {$this->deliveries} SET state = ?, entry = CASE WHEN ? IN ('accepted', 'rejected', 'unknown') THEN '' ELSE entry END, reason = CASE WHEN ? = 'accepted' AND (attempts > 1 OR reason IN ('local_preflight_failure', 'ACCOUNT_DAILY_QUOTA_EXCEEDED')) THEN '' WHEN ? = '' THEN reason ELSE ? END, message_id = CASE WHEN ? = '' THEN message_id ELSE ? END, next_attempt = ?, updated_at = ?, synced = 0 WHERE id = ? AND claim = ? AND state = 'sending'",
            [$state, $state, $state, $reason, substr($reason, 0, 128), $messageId, $messageId, time() + min(3600, 30 * (2 ** (int) $row['attempts'])), time(), $row['id'], $row['claim']]
        );
        if ($changed && 'raw' === $row['operation'] && in_array($state, self::FINAL_STATES, true)) {
            $this->dropRawPayloads([$row['content_id']]);
        }

        return $state;
    }

    /**
     * Hands a claimed row back as retry without spending the attempt its claim counted: for rows never submitted
     * because a local step failed, and for recipients SES deferred because the 24-hour quota was used up.
     */
    public function release(array $row, string $reason, int $delay): void
    {
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE {$this->deliveries} SET state = 'retry', attempts = attempts - 1, reason = ?, next_attempt = ?, updated_at = ?, synced = 0 WHERE id = ? AND claim = ? AND state = 'sending'",
            [$reason, time() + $delay, time(), $row['id'], $row['claim']]
        );
    }

    public function recordRequest(string $contentId, int $bytes): void
    {
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE {$this->contents} SET requests = requests + 1, request_bytes = request_bytes + ? WHERE id = ?", [$bytes, $contentId]
        );
    }

    /**
     * @return array<string, mixed> the content row, whose payload is null once the one delivery of a raw content is final;
     *                              a caller that read that delivery before another process completed it skips it
     */
    public function content(string $id): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative("SELECT * FROM {$this->contents} WHERE id = ?", [$id]);
        if (!$row) {
            throw new \RuntimeException('Missing persisted SES content.');
        }
        $row['payload'] = '' === $row['payload'] ? null : json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);

        return $row;
    }

    public function due(string $scope, int $limit): array
    {
        $limit = max(1, min(10000, $limit));

        return $this->entityManager->getConnection()->fetchAllAssociative(
            "SELECT * FROM {$this->deliveries} WHERE scope = ? AND state IN ('pending', 'retry') AND next_attempt <= ? AND attempts < 4 ORDER BY content_id, created_at, id LIMIT {$limit}", [$scope, time()]
        );
    }

    /** Expired claims mean unknown acceptance, never permission to resend. */
    public function expireClaims(string $scope): int
    {
        $db = $this->entityManager->getConnection();
        $now = time();
        $expired = $db->executeStatement(
            "UPDATE {$this->deliveries} SET state = 'unknown', reason = 'worker_interrupted', entry = '', updated_at = ? WHERE scope = ? AND state = 'sending' AND updated_at < ?", [$now, $scope, $now - 600]
        );
        if ($expired) {
            // The rows just expired carry $now. Another unknown row found here is final as well.
            $this->dropRawPayloads($db->fetchFirstColumn("SELECT DISTINCT d.content_id FROM {$this->deliveries} d INNER JOIN {$this->contents} c ON c.id = d.content_id WHERE d.state = 'unknown' AND d.updated_at = ? AND d.scope = ? AND c.operation = 'raw'", [$now, $scope]));
        }

        return $expired;
    }

    /** Called only after SNS signature/topic authentication by CallbackSubscriber. */
    public function recordEvent(array $payload, string $type): void
    {
        $event = ['Send' => 'sent', 'Delivery' => 'delivered', 'Bounce' => 'bounced', 'Complaint' => 'complained', 'Rendering Failure' => 'rendering_failed', 'Reject' => 'rejected'][$type] ?? null;
        if (null === $event) {
            return;
        }
        $id = $payload['mail']['tags']['mautic_delivery_id'][0] ?? '';
        if (!is_string($id) || !preg_match('/^[a-f0-9]{64}$/D', $id)) {
            return;
        }
        $this->assertInstalled();
        // Events can arrive before the HTTP response. Do not overwrite a later terminal event with Send/Delivery.
        $db = $this->entityManager->getConnection();
        $row = $db->fetchAssociative("SELECT d.*, c.operation FROM {$this->deliveries} d INNER JOIN {$this->contents} c ON c.id = d.content_id WHERE d.id = ?", [$id]);
        if (!$row) {
            return;
        }
        $allowed = array_keys(array_filter(self::EVENT_RANK, static fn (int $rank): bool => $rank <= self::EVENT_RANK[$event]));
        $messageId = (string) ($payload['mail']['messageId'] ?? '');
        $changed = $db->executeStatement(
            "UPDATE {$this->deliveries} SET state = 'accepted', event = ?, message_id = CASE WHEN ? = '' THEN message_id ELSE ? END, entry = '', updated_at = ?, synced = 0 WHERE id = ? AND event IN (".implode(', ', array_fill(0, count($allowed), '?')).')',
            [$event, $messageId, $messageId, time(), $id, ...$allowed]
        );
        // A row that was final already dropped its raw content when it became final.
        if ($changed && 'raw' === $row['operation'] && !in_array($row['state'], self::FINAL_STATES, true)) {
            $this->dropRawPayloads([$row['content_id']]);
        }
    }

    /**
     * A raw content row belongs to the one delivery whose message it holds, so a final delivery no longer needs it.
     * Bulk content rows are shared by many deliveries and keep their payload.
     *
     * @param string[] $contentIds contents of final deliveries only
     */
    private function dropRawPayloads(array $contentIds): void
    {
        if ($contentIds) {
            $this->entityManager->getConnection()->executeStatement(
                "UPDATE {$this->contents} SET payload = '' WHERE id IN (".implode(', ', array_fill(0, count($contentIds), '?')).") AND operation = 'raw' AND NOT EXISTS (SELECT 1 FROM {$this->deliveries} d WHERE d.content_id = {$this->contents}.id AND d.state IN ('pending', 'retry', 'sending'))", $contentIds
            );
        }
    }

    public function summary(?int $emailId = null): array
    {
        $this->assertInstalled();
        $db = $this->entityManager->getConnection();
        $where = null === $emailId ? '' : ' WHERE d.email_id = ?';
        $args = null === $emailId ? [] : [$emailId];
        $recipients = $db->fetchAllAssociative("SELECT c.operation, d.state, d.event, d.reason, COUNT(*) AS recipients, SUM(d.attempts) AS attempts FROM {$this->deliveries} d INNER JOIN {$this->contents} c ON c.id = d.content_id{$where} GROUP BY c.operation, d.state, d.event, d.reason", $args);
        $where = null === $emailId ? '' : ' WHERE email_id = ?';
        $requests = $db->fetchAllAssociative("SELECT operation, SUM(requests) AS requests, SUM(request_bytes) AS request_bytes FROM {$this->contents}{$where} GROUP BY operation", $args);

        return ['email_id' => $emailId, 'recipients' => $recipients, 'requests' => $requests];
    }

    /**
     * Reconcile after Mautic inserts its statistics, without adding a DNC/bounce for transport errors.
     *
     * @return array{reconciled: int, without_statistic: int}
     */
    public function syncFailures(int $limit = 1000): array
    {
        $limit = max(1, min(10000, $limit));
        $db = $this->entityManager->getConnection();
        $table = $this->entityManager->getClassMetadata(Stat::class)->getTableName();
        $failed = "{$this->deliveries}.synced = 0 AND ({$this->deliveries}.state = 'rejected' OR {$this->deliveries}.event IN ('rendering_failed', 'rejected', 'bounced'))";
        // Stats may not exist yet (synchronous sending). A day later they never will (for example, the email was deleted):
        // such rows count as reconciled, so they neither wait forever nor block prune.
        $withoutStatistic = $db->executeStatement(
            "UPDATE {$this->deliveries} SET synced = 1 WHERE {$failed} AND {$this->deliveries}.updated_at < ? AND NOT EXISTS (SELECT 1 FROM {$table} s WHERE s.tracking_hash = {$this->deliveries}.tracking_hash AND s.email_id = {$this->deliveries}.email_id)",
            [time() - 86400]
        );
        // Only rows with a statistic are read, so rows still waiting for theirs never hold up the others.
        $rows = $db->fetchAllAssociative("SELECT {$this->deliveries}.id, {$this->deliveries}.tracking_hash, {$this->deliveries}.email_id FROM {$this->deliveries} INNER JOIN {$table} s ON s.tracking_hash = {$this->deliveries}.tracking_hash AND s.email_id = {$this->deliveries}.email_id WHERE {$failed} ORDER BY {$this->deliveries}.updated_at LIMIT {$limit}");
        $count = 0;
        foreach ($rows as $row) {
            $db->executeStatement("UPDATE {$table} SET is_failed = 1 WHERE tracking_hash = ? AND email_id = ?", [$row['tracking_hash'], $row['email_id']]);
            $db->update($this->deliveries, ['synced' => 1], ['id' => $row['id']]);
            ++$count;
        }

        return ['reconciled' => $count, 'without_statistic' => $withoutStatistic];
    }

    /**
     * Deletes finished deliveries last updated before the cutoff, except failures still waiting for reconciliation, and
     * then the contents no delivery uses any more. Works in batches of $batchSize rows until nothing is left.
     *
     * @return array{deliveries: int, contents: int}
     */
    public function prune(int $olderThanDays, int $batchSize = 1000): array
    {
        $this->assertInstalled();
        $db = $this->entityManager->getConnection();
        $batchSize = max(1, min(10000, $batchSize));
        $cutoff = time() - max(1, $olderThanDays) * 86400;
        $deleted = ['deliveries' => 0, 'contents' => 0];
        // Both loops walk the primary key, so every row is read once per run however many batches it takes.
        $last = '';
        do {
            $ids = $db->fetchFirstColumn("SELECT id FROM {$this->deliveries} WHERE id > ? AND state IN ('accepted', 'rejected', 'unknown') AND updated_at < ? AND NOT (synced = 0 AND (state = 'rejected' OR event IN ('rendering_failed', 'rejected', 'bounced'))) ORDER BY id LIMIT {$batchSize}", [$last, $cutoff]);
            if ($ids) {
                // updated_at is checked again: a row an SNS event changed since the SELECT stays.
                $deleted['deliveries'] += $db->executeStatement("DELETE FROM {$this->deliveries} WHERE id IN (".implode(', ', array_fill(0, count($ids), '?')).') AND updated_at < ?', [...$ids, $cutoff]);
                $last = end($ids);
            }
        } while (count($ids) === $batchSize);
        $last = '';
        do {
            $ids = $db->fetchFirstColumn("SELECT c.id FROM {$this->contents} c LEFT JOIN {$this->deliveries} d ON d.content_id = c.id WHERE c.id > ? AND d.id IS NULL AND c.created_at < ? ORDER BY c.id LIMIT {$batchSize}", [$last, $cutoff]);
            if ($ids) {
                // Checked again: a message saved since the SELECT may use the content again, and enqueue() refreshed created_at.
                $deleted['contents'] += $db->executeStatement("DELETE FROM {$this->contents} WHERE id IN (".implode(', ', array_fill(0, count($ids), '?')).") AND created_at < ? AND NOT EXISTS (SELECT 1 FROM {$this->deliveries} d WHERE d.content_id = {$this->contents}.id)", [...$ids, $cutoff]);
                $last = end($ids);
            }
        } while (count($ids) === $batchSize);

        return $deleted;
    }
}
