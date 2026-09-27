<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Tests\Unit\Mailer\Bulk;

use Doctrine\DBAL\Logging\Middleware;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\Mapping\Driver\StaticPHPDriver;
use MauticPlugin\AmazonSesBundle\Mailer\Bulk\DeliveryStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

class DeliveryStoreTest extends TestCase
{
    public static function manager(array $middlewares = []): EntityManager
    {
        $config = ORMSetup::createConfiguration(true);
        $config->setMetadataDriverImpl(new StaticPHPDriver([dirname(__DIR__, 4).'/Entity']));
        $config->setMiddlewares($middlewares);

        return EntityManager::create(['driver' => 'pdo_sqlite', 'memory' => true], $config);
    }

    public static function delivery(string $id = 'first'): array
    {
        $id = hash('sha256', $id);

        return [
            'id' => $id, 'email_id' => 42, 'tracking_hash' => 'tracking-'.$id,
            'operation' => 'bulk', 'reason' => '',
            'common' => ['FromEmailAddress' => 'sender@example.com', 'DefaultContent' => ['Template' => ['TemplateContent' => ['Subject' => 'News', 'Html' => '<p>Hello {{name}}</p>'], 'TemplateData' => '{}']]],
            'entry' => ['Destination' => ['ToAddresses' => ['recipient@example.com']], 'ReplacementEmailContent' => ['ReplacementTemplate' => ['ReplacementTemplateData' => '{"name":"Reader"}']], 'ReplacementTags' => [['Name' => 'mautic_delivery_id', 'Value' => $id]]],
        ];
    }

    /** A raw fallback delivery as the transport saves it: one content per recipient with its base64 MIME message. */
    public static function raw(string $id): array
    {
        $delivery = self::delivery($id);
        $delivery['operation'] = 'raw';
        $delivery['common'] = [
            'Destination' => $delivery['entry']['Destination'],
            'Content' => ['Raw' => ['Data' => base64_encode("Subject: News\r\n\r\nHello {$id}")]],
            'EmailTags' => [['Name' => 'mautic_delivery_id', 'Value' => $delivery['id']]],
        ];
        $delivery['entry'] = [];

        return $delivery;
    }

    /** Creates the outbox tables as an earlier build did, without the given index. */
    public static function installWithout(EntityManager $em, string $index): void
    {
        foreach (DeliveryStore::createSchemaSql($em) as $sql) {
            if (!str_contains($sql, $index)) {
                $em->getConnection()->executeStatement($sql);
            }
        }
    }

    public static function sqlLog(): AbstractLogger
    {
        return new class() extends AbstractLogger {
            public array $sql = [];

            public function log($level, $message, array $context = []): void
            {
                $this->sql[] = $context['sql'] ?? '';
            }
        };
    }

    public function testInstallClaimAndReplay(): void
    {
        $em = self::manager();
        $store = new DeliveryStore($em);
        $store->install();
        $store->install();
        $id = $store->enqueue(self::delivery(), 'scope');
        $first = $store->claim($id, 'scope', 'worker-a');
        self::assertNotNull($first);
        self::assertNull($store->claim($id, 'scope', 'worker-b'));
        $store->complete($first, 'accepted', '', 'ses-id');
        $store->enqueue(self::delivery(), 'scope');
        self::assertNull($store->claim($id, 'scope', 'worker-b'));
        self::assertSame('accepted', $store->summary(42)['recipients'][0]['state']);
    }

    public function testSharedContentIsInsertedOncePerStore(): void
    {
        $log = self::sqlLog();
        $em = self::manager([new Middleware($log)]);
        $db = $em->getConnection();
        $store = new DeliveryStore($em);
        $store->install();
        $store->enqueue(self::delivery('first'), 'scope');
        $store->enqueue(self::delivery('second'), 'scope');
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM ses_bulk_contents'));
        self::assertCount(1, preg_grep('/^INSERT INTO ses_bulk_contents /', $log->sql));
        // Another worker starts without the cache; its insert hits the existing row and is ignored.
        (new DeliveryStore($em))->enqueue(self::delivery('third'), 'scope');
        self::assertCount(2, preg_grep('/^INSERT INTO ses_bulk_contents /', $log->sql));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM ses_bulk_contents'));
        self::assertSame(3, (int) $db->fetchOne('SELECT COUNT(*) FROM ses_bulk_deliveries'));
    }

    public function testDeliveriesAreInsertedInChunksAndAReplayLeavesThemAsTheyAre(): void
    {
        $log = self::sqlLog();
        $em = self::manager([new Middleware($log)]);
        $db = $em->getConnection();
        $store = new DeliveryStore($em);
        $store->install();
        $batches = array_chunk(array_map(static fn (int $i): array => self::delivery("recipient {$i}"), range(1, 450)), 50);
        $ids = array_map(static fn (array $batch): array => array_column($batch, 'id'), $batches);
        $inserts = static fn (): int => count(preg_grep('/^INSERT INTO ses_bulk_deliveries /', $log->sql));

        self::assertSame($ids, $store->enqueueBatches($batches, 'scope'));
        self::assertSame(3, $inserts());
        self::assertSame(450, (int) $db->fetchOne('SELECT COUNT(*) FROM ses_bulk_deliveries'));

        $db->executeStatement("UPDATE ses_bulk_deliveries SET state = 'accepted', reason = 'first outcome'");
        $log->sql = [];
        // No delivery insert is wrapped in a catch on SQLite: a failing one would throw here and roll the message back.
        self::assertSame($ids, $store->enqueueBatches($batches, 'scope'));
        self::assertSame(3, $inserts());
        self::assertSame(450, (int) $db->fetchOne('SELECT COUNT(*) FROM ses_bulk_deliveries'));
        self::assertSame(450, (int) $db->fetchOne("SELECT COUNT(*) FROM ses_bulk_deliveries WHERE state = 'accepted' AND reason = 'first outcome'"));
    }

    public function testLargeEntriesEndAStatementEarly(): void
    {
        $log = self::sqlLog();
        $em = self::manager([new Middleware($log)]);
        $store = new DeliveryStore($em);
        $store->install();
        $batch = [];
        foreach (range(1, 5) as $i) {
            $delivery = self::delivery("large {$i}");
            $delivery['entry']['ReplacementEmailContent']['ReplacementTemplate']['ReplacementTemplateData'] = json_encode(['name' => str_repeat('x', 1500000)]);
            $batch[] = $delivery;
        }
        $store->enqueueBatches([$batch], 'scope');
        // 1.5 MB per entry: the first statement ends with the third row, at 4.5 MB, and the second takes the other two.
        self::assertCount(2, preg_grep('/^INSERT INTO ses_bulk_deliveries /', $log->sql));
        self::assertSame(5, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM ses_bulk_deliveries'));
    }

    public function testFinalOutcomesDropTheRequestData(): void
    {
        $em = self::manager();
        $db = $em->getConnection();
        $store = new DeliveryStore($em);
        $store->install();
        $claim = static fn (string $name): array => $store->claim($store->enqueue(self::delivery($name), 'scope'), 'scope', 'worker');
        $store->complete($claim('accepted'), 'accepted', '', 'ses-id');
        $store->complete($claim('rejected'), 'rejected', 'MESSAGE_REJECTED');
        $store->complete($claim('retry'), 'retry', 'TRANSIENT_FAILURE');
        $store->enqueue(self::delivery('exhausted'), 'scope');
        $db->executeStatement('UPDATE ses_bulk_deliveries SET attempts = 3 WHERE id = ?', [hash('sha256', 'exhausted')]);
        $store->complete($store->claim(hash('sha256', 'exhausted'), 'scope', 'worker'), 'retry', 'TRANSIENT_FAILURE');
        $expired = $claim('expired');
        $db->executeStatement('UPDATE ses_bulk_deliveries SET updated_at = 0 WHERE id = ?', [$expired['id']]);
        self::assertSame(1, $store->expireClaims('scope'));
        $sending = $claim('event');
        $store->recordEvent(['mail' => ['messageId' => 'ses-id', 'tags' => ['mautic_delivery_id' => [$sending['id']]]]], 'Delivery');

        $rows = $db->fetchAllAssociativeIndexed('SELECT id, state, reason, entry FROM ses_bulk_deliveries');
        $expected = [
            'accepted' => ['accepted', '', ''],
            'rejected' => ['rejected', 'MESSAGE_REJECTED', ''],
            'retry' => ['retry', 'TRANSIENT_FAILURE', json_encode(self::delivery('retry')['entry'], JSON_THROW_ON_ERROR)],
            'exhausted' => ['rejected', 'retry_exhausted:TRANSIENT_FAILURE', ''],
            'expired' => ['unknown', 'worker_interrupted', ''],
            'event' => ['accepted', '', ''],
        ];
        foreach ($expected as $name => $row) {
            self::assertSame($row, array_values($rows[hash('sha256', $name)]), $name);
        }
    }

    public function testFinalRawDeliveryDropsItsContentWhileSharedContentStays(): void
    {
        $em = self::manager();
        $db = $em->getConnection();
        $store = new DeliveryStore($em);
        $store->install();
        $ids = [];
        foreach (['accepted', 'expired', 'event', 'retry'] as $name) {
            $ids[$name] = $store->enqueue(self::raw($name), 'scope');
        }
        // Two recipients of one bulk message share their content.
        $ids['bulk accepted'] = $store->enqueue(self::delivery('bulk accepted'), 'scope');
        $ids['bulk pending'] = $store->enqueue(self::delivery('bulk pending'), 'scope');
        $store->complete($store->claim($ids['accepted'], 'scope', 'worker'), 'accepted', '', 'ses-id');
        $store->complete($store->claim($ids['bulk accepted'], 'scope', 'worker'), 'accepted', '', 'ses-id');
        $store->complete($store->claim($ids['retry'], 'scope', 'worker'), 'retry', 'TRANSIENT_FAILURE');
        $store->claim($ids['expired'], 'scope', 'worker');
        $db->executeStatement('UPDATE ses_bulk_deliveries SET updated_at = 0 WHERE id = ?', [$ids['expired']]);
        self::assertSame(1, $store->expireClaims('scope'));
        $store->claim($ids['event'], 'scope', 'worker');
        $store->recordEvent(['mail' => ['messageId' => 'ses-id', 'tags' => ['mautic_delivery_id' => [$ids['event']]]]], 'Send');

        $payloads = $db->fetchAllKeyValue('SELECT d.id, c.payload FROM ses_bulk_deliveries d INNER JOIN ses_bulk_contents c ON c.id = d.content_id');
        foreach (['accepted', 'expired', 'event'] as $name) {
            self::assertSame('', $payloads[$ids[$name]], $name);
        }
        self::assertSame(json_encode(self::raw('retry')['common'], JSON_THROW_ON_ERROR), $payloads[$ids['retry']]);
        self::assertSame(json_encode(self::delivery()['common'], JSON_THROW_ON_ERROR), $payloads[$ids['bulk accepted']]);
        self::assertSame(['bulk' => 1, 'raw' => 1], $db->fetchAllKeyValue("SELECT operation, COUNT(*) FROM ses_bulk_contents WHERE payload <> '' GROUP BY operation ORDER BY operation"));

        // What retryBulk() reads: only rows still due, whose content and entry are intact.
        $db->executeStatement('UPDATE ses_bulk_deliveries SET next_attempt = 0');
        $due = $store->due('scope', 100);
        self::assertEqualsCanonicalizing([$ids['retry'], $ids['bulk pending']], array_column($due, 'id'));
        foreach ($due as $row) {
            self::assertNotSame([], $store->content($row['content_id'])['payload']);
            self::assertIsArray(json_decode($row['entry'], true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function testDeliveryMadeFinalElsewhereSinceDueReadsAsDroppedContent(): void
    {
        $em = self::manager();
        $store = new DeliveryStore($em);
        $store->install();
        $raw = $store->enqueue(self::raw('raw'), 'scope');
        $bulk = $store->enqueue(self::delivery('bulk'), 'scope');
        $due = array_column($store->due('scope', 100), null, 'id');
        self::assertEqualsCanonicalizing([$raw, $bulk], array_keys($due));

        // Another process, for example the sending worker, completes the raw delivery after due() listed it.
        $other = new DeliveryStore($em);
        $other->complete($other->claim($raw, 'scope', 'other-worker'), 'accepted', '', 'ses-id');

        self::assertNull($store->content($due[$raw]['content_id'])['payload']);
        self::assertNull($store->claim($raw, 'scope', 'worker'));
        self::assertSame(self::delivery('bulk')['common'], $store->content($due[$bulk]['content_id'])['payload']);
    }

    public function testReplayAfterPruneRestoresTheRawContent(): void
    {
        $em = self::manager();
        $db = $em->getConnection();
        $store = new DeliveryStore($em);
        $store->install();
        $raw = self::raw('replayed');
        $store->enqueueBatches([[$raw]], 'scope');
        $store->complete($store->claim($raw['id'], 'scope', 'worker'), 'accepted', '', 'ses-id');
        // The delivery is pruned. Its content is newer than the cutoff, as after an earlier replay, and stays.
        $db->executeStatement('UPDATE ses_bulk_deliveries SET updated_at = ?', [time() - 31 * 86400]);
        self::assertSame(['deliveries' => 1, 'contents' => 0], $store->prune(30));
        self::assertSame('', $db->fetchOne('SELECT payload FROM ses_bulk_contents'));

        // The same Messenger message is replayed: its delivery is saved again and needs the message.
        $store->enqueueBatches([[$raw]], 'scope');
        $due = $store->due('scope', 100);
        self::assertSame([$raw['id']], array_column($due, 'id'));
        self::assertSame($raw['common'], $store->content($due[0]['content_id'])['payload']);
    }

    public function testInstallAddsIndexesMissingFromExistingTables(): void
    {
        $em = self::manager();
        $db = $em->getConnection();
        self::installWithout($em, 'ses_bulk_state_updated');
        self::assertArrayNotHasKey('ses_bulk_state_updated', $db->createSchemaManager()->listTableIndexes('ses_bulk_deliveries'));

        (new DeliveryStore($em))->install();
        $indexes = $db->createSchemaManager()->listTableIndexes('ses_bulk_deliveries');
        self::assertArrayHasKey('ses_bulk_state_updated', $indexes);
        self::assertSame(['state', 'updated_at'], $indexes['ses_bulk_state_updated']->getColumns());
        self::assertSame([], DeliveryStore::createSchemaSql($em));
    }

    public function testAcceptedRetryClearsEarlierFailureReason(): void
    {
        $em = self::manager();
        $store = new DeliveryStore($em);
        $store->install();
        $id = $store->enqueue(self::delivery(), 'scope');
        $store->complete($store->claim($id, 'scope', 'worker-a'), 'retry', 'TRANSIENT_FAILURE');
        self::assertSame('TRANSIENT_FAILURE', $store->summary(42)['recipients'][0]['reason']);
        $em->getConnection()->executeStatement('UPDATE ses_bulk_deliveries SET next_attempt = 0');
        $retried = $store->claim($id, 'scope', 'worker-b');
        self::assertNotNull($retried);
        $store->complete($retried, 'accepted', '', 'ses-id');
        $recipients = $store->summary(42)['recipients'];
        self::assertCount(1, $recipients);
        self::assertSame('accepted', $recipients[0]['state']);
        self::assertSame('', $recipients[0]['reason']);
    }

    public function testAcceptedFirstAttemptKeepsRawFallbackReason(): void
    {
        $store = new DeliveryStore(self::manager());
        $store->install();
        $id = $store->enqueue(['operation' => 'raw', 'reason' => 'literal_template_delimiters'] + self::delivery(), 'scope');
        $store->complete($store->claim($id, 'scope', 'worker'), 'accepted', '', 'ses-id');
        $recipients = $store->summary(42)['recipients'];
        self::assertCount(1, $recipients);
        self::assertSame(['raw', 'accepted', 'literal_template_delimiters'], [$recipients[0]['operation'], $recipients[0]['state'], $recipients[0]['reason']]);
    }

    public function testExpiredClaimsAreUnknownNotRetryable(): void
    {
        $em = self::manager();
        $store = new DeliveryStore($em);
        $store->install();
        $id = $store->enqueue(self::delivery(), 'scope');
        $store->claim($id, 'scope', 'dead-worker');
        $em->getConnection()->executeStatement('UPDATE ses_bulk_deliveries SET updated_at = 0');
        self::assertSame(1, $store->expireClaims('scope'));
        self::assertSame([], $store->due('scope', 100));
        self::assertSame('unknown', $store->summary()['recipients'][0]['state']);
    }

    public function testNotificationBeforeResponseAndOutOfOrderEvents(): void
    {
        $store = new DeliveryStore(self::manager());
        $store->install();
        $id = $store->enqueue(self::delivery(), 'scope');
        $row = $store->claim($id, 'scope', 'worker');
        $event = ['mail' => ['messageId' => 'ses-id', 'tags' => ['mautic_delivery_id' => [$id]]]];
        $store->recordEvent($event, 'Rendering Failure');
        $store->complete($row, 'accepted', '', 'ses-id');
        $store->recordEvent($event, 'Send');
        self::assertSame('rendering_failed', $store->summary()['recipients'][0]['event']);
        self::assertSame([], $store->due('scope', 100));
    }

    public function testStatsReconciliationWaitsForStatAndDoesNotAddDnc(): void
    {
        $em = self::manager();
        $store = new DeliveryStore($em);
        $store->install();
        $em->getConnection()->executeStatement('CREATE TABLE email_stats (id INTEGER PRIMARY KEY, email_id INTEGER, tracking_hash VARCHAR(191), is_failed INTEGER)');
        $delivery = self::delivery();
        $id = $store->enqueue($delivery, 'scope');
        $row = $store->claim($id, 'scope', 'worker');
        $store->complete($row, 'rejected', 'INVALID_PARAMETER');
        self::assertSame(['reconciled' => 0, 'without_statistic' => 0], $store->syncFailures());
        $em->getConnection()->insert('email_stats', ['id' => 1, 'email_id' => 42, 'tracking_hash' => $delivery['tracking_hash'], 'is_failed' => 0]);
        self::assertSame(['reconciled' => 1, 'without_statistic' => 0], $store->syncFailures());
        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT is_failed FROM email_stats'));
        self::assertSame(['reconciled' => 0, 'without_statistic' => 0], $store->syncFailures());
    }

    public function testRowsWithoutStatisticNeverHoldUpReconciliation(): void
    {
        $em = self::manager();
        $db = $em->getConnection();
        $store = new DeliveryStore($em);
        $store->install();
        $db->executeStatement('CREATE TABLE email_stats (id INTEGER PRIMARY KEY, email_id INTEGER, tracking_hash VARCHAR(191), is_failed INTEGER)');
        foreach (['orphan', 'current'] as $name) {
            $store->complete($store->claim($store->enqueue(self::delivery($name), 'scope'), 'scope', 'worker'), 'rejected', 'MESSAGE_REJECTED');
        }
        // The orphan is older and its statistic is gone, for example because its email was deleted.
        $db->executeStatement('UPDATE ses_bulk_deliveries SET updated_at = ? WHERE id = ?', [time() - 3600, hash('sha256', 'orphan')]);
        $db->insert('email_stats', ['id' => 1, 'email_id' => 42, 'tracking_hash' => 'tracking-'.hash('sha256', 'current'), 'is_failed' => 0]);

        self::assertSame(['reconciled' => 1, 'without_statistic' => 0], $store->syncFailures(1));
        self::assertSame(1, (int) $db->fetchOne('SELECT is_failed FROM email_stats'));
        // Within a day the orphan keeps waiting for its statistic; after that it counts as reconciled.
        $db->executeStatement('UPDATE ses_bulk_deliveries SET updated_at = ? WHERE id = ?', [time() - 86401, hash('sha256', 'orphan')]);
        self::assertSame(['reconciled' => 0, 'without_statistic' => 1], $store->syncFailures(1));
        self::assertSame(['reconciled' => 0, 'without_statistic' => 0], $store->syncFailures(1));
    }

    public function testReleaseDoesNotSpendTheAttempt(): void
    {
        $em = self::manager();
        $store = new DeliveryStore($em);
        $store->install();
        $id = $store->enqueue(self::delivery(), 'scope');
        for ($i = 0; $i < 5; ++$i) {
            $store->release($store->claim($id, 'scope', 'worker'), 'local_preflight_failure', 60);
            $row = $em->getConnection()->fetchAssociative('SELECT state, attempts, reason, next_attempt FROM ses_bulk_deliveries');
            self::assertSame(['retry', 0, 'local_preflight_failure'], [$row['state'], (int) $row['attempts'], $row['reason']]);
            self::assertGreaterThanOrEqual(time() + 59, (int) $row['next_attempt']);
            $em->getConnection()->executeStatement('UPDATE ses_bulk_deliveries SET next_attempt = 0');
        }
        // Accepted on the first real attempt, the release reason does not stay.
        self::assertSame('accepted', $store->complete($store->claim($id, 'scope', 'worker'), 'accepted', '', 'ses-id'));
        self::assertSame(['accepted', '', 1], array_map(static fn ($value) => is_numeric($value) ? (int) $value : $value, array_values($em->getConnection()->fetchAssociative('SELECT state, reason, attempts FROM ses_bulk_deliveries'))));
    }

    public function testFailedMessageLeavesNothingBehind(): void
    {
        $em = self::manager();
        $db = $em->getConnection();
        $store = new DeliveryStore($em);
        $store->install();
        $batches = (static function (): \Generator {
            yield [self::delivery('first')];
            throw new \RuntimeException('Rendering failed');
        })();
        try {
            $store->enqueueBatches($batches, 'scope');
            self::fail('The failure must propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('Rendering failed', $e->getMessage());
        }
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM ses_bulk_deliveries'));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM ses_bulk_contents'));
        // The rolled-back content is inserted again for the next attempt instead of being taken as present.
        self::assertSame([[hash('sha256', 'first')], [hash('sha256', 'second')]], $store->enqueueBatches([[self::delivery('first')], [self::delivery('second')]], 'scope'));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM ses_bulk_contents'));
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM ses_bulk_deliveries'));
    }

    public function testPruneDeletesOnlyFinishedRowsPastTheCutoff(): void
    {
        $em = self::manager();
        $db = $em->getConnection();
        $store = new DeliveryStore($em);
        $store->install();
        $old = time() - 31 * 86400;
        $rows = [
            'accepted old' => ['accepted', 1, $old, true],
            'unknown old' => ['unknown', 1, $old, true],
            'rejected reconciled' => ['rejected', 1, $old, true],
            'rejected unreconciled' => ['rejected', 0, $old, false],
            'accepted recent' => ['accepted', 1, time() - 86400, false],
            'retry old' => ['retry', 0, $old, false],
            'pending old' => ['pending', 0, $old, false],
        ];
        foreach ($rows as $name => [$state, $synced, $updated]) {
            $delivery = self::delivery($name);
            // One content per delivery, so each content lives exactly as long as its delivery.
            $delivery['common']['Name'] = $name;
            $store->enqueue($delivery, 'scope');
            $db->executeStatement('UPDATE ses_bulk_deliveries SET state = ?, synced = ?, updated_at = ? WHERE id = ?', [$state, $synced, $updated, $delivery['id']]);
        }
        $db->executeStatement('UPDATE ses_bulk_contents SET created_at = ?', [$old]);

        // A batch size of 1 walks every row in its own batch.
        self::assertSame(['deliveries' => 3, 'contents' => 3], $store->prune(30, 1));
        $kept = array_filter($rows, static fn (array $row): bool => !$row[3]);
        self::assertEqualsCanonicalizing(array_map(static fn (string $name): string => hash('sha256', $name), array_keys($kept)), $db->fetchFirstColumn('SELECT id FROM ses_bulk_deliveries'));
        self::assertSame(4, (int) $db->fetchOne('SELECT COUNT(*) FROM ses_bulk_contents'));
        self::assertSame(['deliveries' => 0, 'contents' => 0], $store->prune(30));

        // An old content that a new message uses again gets a fresh created_at, so prune keeps it once its deliveries are gone.
        $again = self::delivery('again');
        $again['common']['Name'] = 'rejected unreconciled';
        $store->enqueueBatches([[$again]], 'scope');
        self::assertSame(4, (int) $db->fetchOne('SELECT COUNT(*) FROM ses_bulk_contents'));
        $db->executeStatement('UPDATE ses_bulk_deliveries SET state = ?, synced = 1, updated_at = ? WHERE id IN (?, ?)', ['accepted', $old, $again['id'], hash('sha256', 'rejected unreconciled')]);
        self::assertSame(['deliveries' => 2, 'contents' => 0], $store->prune(30));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM ses_bulk_contents WHERE created_at > ?', [$old]));
    }

    /** @dataProvider concurrentEvents */
    public function testConcurrentEventsKeepTheHigherRank(string $outer, string $inner, string $expected): void
    {
        $logger = new class() extends AbstractLogger {
            public ?\Closure $interleave = null;
            public bool $interleaved = false;

            public function log($level, $message, array $context = []): void
            {
                if ($this->interleave && str_starts_with($context['sql'] ?? '', "UPDATE ses_bulk_deliveries SET state = 'accepted', event =")) {
                    $callback = $this->interleave;
                    $this->interleave = null;
                    $this->interleaved = true;
                    $callback();
                }
            }
        };
        $em = DeliveryStoreTest::manager([new Middleware($logger)]);
        $store = new DeliveryStore($em);
        $store->install();
        $id = $store->enqueue(DeliveryStoreTest::delivery(), 'scope');
        $payload = ['mail' => ['messageId' => 'ses-id', 'tags' => ['mautic_delivery_id' => [$id]]]];
        // A competing callback commits between the initial read and the conditional event update.
        $logger->interleave = fn () => (new DeliveryStore($em))->recordEvent($payload, $inner);
        $store->recordEvent($payload, $outer);
        self::assertTrue($logger->interleaved);
        self::assertSame($expected, $store->summary()['recipients'][0]['event']);
        if ('rendering_failed' === $expected) {
            $db = $em->getConnection();
            $db->executeStatement('CREATE TABLE email_stats (id INTEGER PRIMARY KEY, email_id INTEGER, tracking_hash VARCHAR(191), is_failed INTEGER)');
            $db->insert('email_stats', ['id' => 1, 'email_id' => 42, 'tracking_hash' => self::delivery()['tracking_hash'], 'is_failed' => 0]);
            self::assertSame(['reconciled' => 1, 'without_statistic' => 0], $store->syncFailures());
            self::assertSame(1, (int) $db->fetchOne('SELECT is_failed FROM email_stats'));
        }
    }

    public static function concurrentEvents(): array
    {
        return [
            ['Rendering Failure', 'Send', 'rendering_failed'],
            ['Send', 'Rendering Failure', 'rendering_failed'],
            ['Complaint', 'Delivery', 'complained'],
            ['Delivery', 'Complaint', 'complained'],
            ['Rendering Failure', 'Rendering Failure', 'rendering_failed'],
            ['Reject', 'Bounce', 'rejected'],
        ];
    }

    /** @dataProvider terminalRawReplays */
    public function testReplayKeepsFinalRawPayloadDropped(string $state, bool $batch): void
    {
        $em = DeliveryStoreTest::manager();
        $store = new DeliveryStore($em);
        $store->install();
        $raw = DeliveryStoreTest::raw('replay');
        $store->enqueueBatches([[$raw]], 'scope');
        $store->complete($store->claim($raw['id'], 'scope', 'worker'), $state, '', 'ses-id');
        self::assertSame('', $em->getConnection()->fetchOne('SELECT payload FROM ses_bulk_contents'));
        if ($batch) {
            $store->enqueueBatches([[$raw]], 'scope');
        } else {
            $store->enqueue($raw, 'scope');
        }
        self::assertNull($store->claim($raw['id'], 'scope', 'replay-worker'));
        self::assertSame('', $em->getConnection()->fetchOne('SELECT payload FROM ses_bulk_contents'));
    }

    public static function terminalRawReplays(): array
    {
        return [['accepted', true], ['rejected', true], ['unknown', true], ['accepted', false], ['rejected', false], ['unknown', false]];
    }

    public function testOwnershipLookupIncludesEveryStateAndIsBoundedByScope(): void
    {
        $log = self::sqlLog();
        $em = self::manager([new Middleware($log)]);
        $store = new DeliveryStore($em);
        self::assertSame([], $store->existingIds([self::delivery()['id']], 'scope'));
        $store->install();
        $deliveries = array_map(static fn (int $i): array => self::delivery('owned '.$i), range(1, 450));
        $store->enqueueBatches(array_chunk($deliveries, 50), 'scope');
        $ids = array_column($deliveries, 'id');
        $em->getConnection()->executeStatement("UPDATE ses_bulk_deliveries SET state = 'accepted'");
        $log->sql = [];
        self::assertEqualsCanonicalizing($ids, array_keys($store->existingIds($ids, 'scope')));
        self::assertCount(3, preg_grep('/^SELECT id FROM ses_bulk_deliveries /', $log->sql));
        self::assertSame([], $store->existingIds($ids, 'another-scope'));
        self::assertSame([], iterator_to_array($store->dueForIds($ids, 'scope')));
    }

    public function testBulkTerminalTransitionsDoNotUpdateContentPayloads(): void
    {
        $log = self::sqlLog();
        $em = self::manager([new Middleware($log)]);
        $store = new DeliveryStore($em);
        $store->install();
        $ids = [];
        foreach (['complete', 'event', 'expire'] as $name) {
            $ids[$name] = $store->enqueue(self::delivery($name), 'scope');
        }
        $completed = $store->claim($ids['complete'], 'scope', 'worker');
        $store->claim($ids['expire'], 'scope', 'worker');
        $em->getConnection()->update('ses_bulk_deliveries', ['updated_at' => 0], ['id' => $ids['expire']]);
        $log->sql = [];
        $store->complete($completed, 'accepted');
        $store->recordEvent(['mail' => ['tags' => ['mautic_delivery_id' => [$ids['event']]]]], 'Delivery');
        self::assertSame(1, $store->expireClaims('scope'));
        self::assertCount(0, preg_grep("/^UPDATE ses_bulk_contents SET payload = ''/", $log->sql));

        $raw = $store->enqueue(self::raw('raw cleanup'), 'scope');
        $store->complete($store->claim($raw, 'scope', 'worker'), 'accepted');
        self::assertCount(1, preg_grep("/^UPDATE ses_bulk_contents SET payload = ''/", $log->sql));
        self::assertSame('', $em->getConnection()->fetchOne("SELECT payload FROM ses_bulk_contents WHERE operation = 'raw'"));
    }

    public function testEventWithoutMessageIdKeepsTheCurrentMessageId(): void
    {
        $em = self::manager();
        $store = new DeliveryStore($em);
        $store->install();
        $id = $store->enqueue(self::delivery(), 'scope');
        $store->complete($store->claim($id, 'scope', 'worker'), 'accepted', '', 'saved-id');
        $store->recordEvent(['mail' => ['messageId' => '', 'tags' => ['mautic_delivery_id' => [$id]]]], 'Delivery');
        self::assertSame('saved-id', $em->getConnection()->fetchOne('SELECT message_id FROM ses_bulk_deliveries'));
    }
}
