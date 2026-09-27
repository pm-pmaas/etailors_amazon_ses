<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Mailer\Bulk;

use Aws\Exception\AwsException;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Log\LoggerInterface;

final class BulkSender
{
    /** A recipient over the 24-hour quota is retried hourly, without spending attempts, for this long after it was saved. */
    public const QUOTA_DEFERRAL = 30 * 3600;

    public function __construct(private DeliveryStore $store, private LoggerInterface $logger)
    {
    }

    /**
     * The outbox partition of the configured SES region. The access key is left out: rotating it must neither hide saved
     * recipients from the retry command nor let a replayed queue message send accepted recipients again.
     */
    public static function scope(SesV2Client $client): string
    {
        return hash('sha256', $client->getRegion());
    }

    public static function assertSupported(SesV2Client $client): void
    {
        $input = $client->getApi()->getOperation('SendBulkEmail')->getInput();
        if (!$input->getMember('DefaultContent')->getMember('Template')->hasMember('TemplateContent') || !$input->getMember('BulkEmailEntries')->getMember()->hasMember('ReplacementHeaders')) {
            throw new \RuntimeException('The installed AWS SDK lacks SES inline templates/replacement headers. Update aws/aws-sdk-php before enabling bulk=auto.');
        }
    }

    /**
     * @param iterable<list<string>> $batches each batch becomes one request per content group; batches are never merged
     * @param callable(int): void    $acquire
     */
    public function send(SesV2Client $client, iterable $batches, callable $acquire, int $concurrency = 1): void
    {
        $scope = self::scope($client);
        $owner = bin2hex(random_bytes(16));
        // Prepare a bounded window before reserving quota: the SDK may defer all HTTP dispatch until wait().
        $window = [];
        // Claimed rows of the current batch that have not been submitted, by content id.
        $pending = [];
        try {
            foreach ($batches as $ids) {
                // Claim one batch at a time, so rows of later batches stay untouched until their turn.
                foreach ($ids as $id) {
                    if ($row = $this->store->claim($id, $scope, $owner)) {
                        $pending[$row['content_id']][] = $row;
                    }
                }
                foreach ($pending as $contentId => $rows) {
                    if (count($window) >= max(1, $concurrency)) {
                        $this->sendWindow($client, $window, $acquire);
                    }
                    $content = $this->store->content($contentId);
                    if (null === $content['payload']) {
                        // An SNS event made this raw delivery final since its claim, and the claim ended with it.
                        unset($pending[$contentId]);
                        continue;
                    }
                    $entries = array_map(static fn (array $row): array => json_decode($row['entry'], true, 512, JSON_THROW_ON_ERROR), $rows);
                    if ('bulk' === $content['operation']) {
                        $request = BulkBatcher::request($content['payload'], $entries);
                        $operation = 'SendBulkEmail';
                        $recipients = count($entries);
                    } else {
                        $request = $content['payload'];
                        $request['Content']['Raw']['Data'] = base64_decode($request['Content']['Raw']['Data'], true);
                        $operation = 'SendEmail';
                        $recipients = array_sum(array_map('count', $request['Destination']));
                    }
                    // Keep separate slots even when successive batches share the same content.
                    $window[] = compact('operation', 'request', 'recipients', 'rows', 'content', 'contentId');
                    unset($pending[$contentId]);
                }
            }
            $this->sendWindow($client, $window, $acquire);
        } finally {
            // A local step failed before these claims were submitted. Releasing them for a later retry is safe, and it does
            // not spend one of their attempts, because nothing reached SES.
            foreach ([...array_values($pending), ...array_column($window, 'rows')] as $rows) {
                foreach ($rows as $row) {
                    $this->store->release($row, 'local_preflight_failure', 60);
                }
            }
        }
    }

    /** Reserve a complete window, submit it without another quota wait, and settle it before the next reservation. */
    private function sendWindow(SesV2Client $client, array &$window, callable $acquire): void
    {
        if (!$window) {
            return;
        }
        $acquire(array_sum(array_column($window, 'recipients')));
        $inFlight = [];
        try {
            foreach ($window as $index => $prepared) {
                ['operation' => $operation, 'request' => $request, 'rows' => $rows, 'content' => $content, 'contentId' => $contentId] = $prepared;
                $this->store->recordRequest($contentId, BulkBatcher::bytes($request));
                // From here on the request may reach SES, so its outcome is recorded rather than released.
                unset($window[$index]);
                try {
                    // Disable hidden whole-request retries. Outcome handling belongs to this ledger.
                    $command = $client->getCommand($operation, $request + [
                        '@retries' => 0,
                        '@http' => ['connect_timeout' => 10, 'timeout' => 60],
                    ]);
                    $inFlight[] = [$client->executeAsync($command), $rows, $content, $contentId];
                } catch (\Throwable $e) {
                    $this->recordFailure($rows, $e);
                }
            }
            $window = [];
        } finally {
            // No quota wait can delay queued SDK requests; record submitted outcomes even if later bookkeeping fails.
            while ($inFlight) {
                $this->settle(...array_shift($inFlight));
            }
        }
    }

    private function settle(PromiseInterface $promise, array $rows, array $content, string $contentId): void
    {
        try {
            $result = $promise->wait();
            $this->recordResult($rows, $content, $contentId, $result);
        } catch (\Throwable $e) {
            $this->recordFailure($rows, $e);
        }
    }

    private function recordResult(array $rows, array $content, string $contentId, Result $result): void
    {
        $outcomes = [];
        if ('raw' === $content['operation']) {
            $messageId = (string) ($result['MessageId'] ?? '');
            $reason = $messageId ? '' : 'missing_result';
            self::tally($outcomes, $this->store->complete($rows[0], $messageId ? 'accepted' : 'unknown', $reason, $messageId), $reason);
            $this->logOutcomes($outcomes, $content['email_id'], $contentId);

            return;
        }
        $results = $result['BulkEmailEntryResults'] ?? [];
        // Positional results cannot safely be mapped if the count differs.
        if (!is_array($results) || count($results) !== count($rows)) {
            foreach ($rows as $row) {
                self::tally($outcomes, $this->store->complete($row, 'unknown', 'invalid_result_count'), 'invalid_result_count');
            }
            $this->logOutcomes($outcomes, $content['email_id'], $contentId);

            return;
        }
        foreach ($rows as $i => $row) {
            $entry = $results[$i] ?? [];
            $status = $entry['Status'] ?? '';
            $messageId = (string) ($entry['MessageId'] ?? '');
            if ('SUCCESS' === $status && '' !== $messageId) {
                self::tally($outcomes, $this->store->complete($row, 'accepted', '', $messageId), '');
            } elseif ('ACCOUNT_DAILY_QUOTA_EXCEEDED' === $status && time() - (int) $row['created_at'] < self::QUOTA_DEFERRAL) {
                // The 24-hour quota frees up gradually. Waiting an hour between attempts, without spending one of the
                // four attempts, lets the retry command drain these recipients as the quota returns.
                $this->store->release($row, $status, 3600);
                self::tally($outcomes, 'retry', $status);
            } elseif (in_array($status, ['ACCOUNT_THROTTLED', 'ACCOUNT_DAILY_QUOTA_EXCEEDED', 'TRANSIENT_FAILURE', 'FAILED'], true)) {
                self::tally($outcomes, $this->store->complete($row, 'retry', $status), $status);
            } elseif (in_array($status, ['MESSAGE_REJECTED', 'MAIL_FROM_DOMAIN_NOT_VERIFIED', 'CONFIGURATION_SET_NOT_FOUND', 'CONFIGURATION_SET_DOES_NOT_EXIST', 'TEMPLATE_NOT_FOUND', 'TEMPLATE_DOES_NOT_EXIST', 'ACCOUNT_SUSPENDED', 'INVALID_SENDING_POOL_NAME', 'ACCOUNT_SENDING_PAUSED', 'CONFIGURATION_SET_SENDING_PAUSED', 'INVALID_PARAMETER', 'INVALID_PARAMETER_VALUE'], true)) {
                self::tally($outcomes, $this->store->complete($row, 'rejected', $status), $status);
            } else {
                self::tally($outcomes, $this->store->complete($row, 'unknown', 'unrecognized_result'), 'unrecognized_result');
            }
        }
        $this->logOutcomes($outcomes, $content['email_id'], $contentId);
    }

    private function recordFailure(array $rows, \Throwable $e): void
    {
        // SDK input validation failed before dispatch; otherwise the request itself failed.
        [$state, $reason] = $e instanceof \InvalidArgumentException ? ['rejected', 'sdk_validation'] : $this->exceptionOutcome($e);
        $outcomes = [];
        foreach ($rows as $row) {
            self::tally($outcomes, $this->store->complete($row, $state, $reason), $reason);
        }
        $this->logOutcomes($outcomes, $rows[0]['email_id'] ?? null, $rows[0]['content_id'] ?? null, $e);
    }

    /** @param array<string, array<string, int>> $outcomes recipients by state and reason */
    private static function tally(array &$outcomes, string $state, string $reason): void
    {
        $outcomes[$state][$reason] = ($outcomes[$state][$reason] ?? 0) + 1;
    }

    /**
     * Mautic's production log keeps only errors, and Mautic already counts these recipients as sent, so a request that
     * leaves any recipient rejected or unknown is logged as an error.
     *
     * @param array<string, array<string, int>> $outcomes recipients by state and reason
     */
    private function logOutcomes(array $outcomes, mixed $emailId, ?string $contentId, ?\Throwable $e = null): void
    {
        $context = ['email_id' => $emailId, 'content_id' => $contentId, 'recipients' => array_sum(array_map('array_sum', $outcomes)), 'outcomes' => $outcomes] + ($e ? ['exception' => $e] : []);
        if (isset($outcomes['rejected']) || isset($outcomes['unknown'])) {
            $this->logger->error('SES request left recipients rejected or unknown; see mautic:ses:bulk status.', $context);
        } elseif (isset($outcomes['retry'])) {
            $this->logger->warning('SES request left recipients for mautic:ses:bulk retry.', $context);
        } else {
            $this->logger->info('SES shared-template request processed.', $context);
        }
    }

    private function exceptionOutcome(\Throwable $e): array
    {
        if ($e instanceof AwsException) {
            $code = (string) $e->getAwsErrorCode();
            if (in_array($code, ['TooManyRequestsException', 'Throttling', 'ThrottlingException', 'LimitExceededException'], true)) {
                return ['retry', $code];
            }
            if (in_array($code, ['BadRequestException', 'MessageRejected', 'MailFromDomainNotVerifiedException', 'NotFoundException', 'AccountSuspendedException', 'SendingPausedException', 'AccessDeniedException', 'UnrecognizedClientException', 'InvalidSignatureException'], true)) {
                return ['rejected', $code];
            }
        }

        // Timeouts, broken connections and 5xx responses can follow acceptance.
        return ['unknown', 'ambiguous_request_failure'];
    }
}
