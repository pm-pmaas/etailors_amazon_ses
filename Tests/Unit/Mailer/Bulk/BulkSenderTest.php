<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Tests\Unit\Mailer\Bulk;

use Aws\Exception\AwsException;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use Doctrine\ORM\EntityManager;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Promise;
use MauticPlugin\AmazonSesBundle\Mailer\Bulk\BulkSender;
use MauticPlugin\AmazonSesBundle\Mailer\Bulk\DeliveryStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

class BulkSenderTest extends TestCase
{
    public function testPartialResultsRetryOnlyFailedRecipientsAndChargeQuota(): void
    {
        $calls = [];
        $client = self::client(function ($command) use (&$calls) {
            $calls[] = $command->toArray();
            return Create::promiseFor(new Result(['BulkEmailEntryResults' => 1 === count($calls) ? [
                ['Status' => 'SUCCESS', 'MessageId' => 'first-id'], ['Status' => 'TRANSIENT_FAILURE'],
            ] : [['Status' => 'SUCCESS', 'MessageId' => 'second-id']]]));
        });
        BulkSender::assertSupported($client);
        $em = DeliveryStoreTest::manager();
        $store = new DeliveryStore($em);
        $store->install();
        $scope = BulkSender::scope($client);
        $ids = [$store->enqueue(DeliveryStoreTest::delivery('a'), $scope), $store->enqueue(DeliveryStoreTest::delivery('b'), $scope)];
        $sender = new BulkSender($store, new NullLogger());
        $tokens = [];
        $acquire = static function (int $count) use (&$tokens): void { $tokens[] = $count; };
        $sender->send($client, [$ids], $acquire);
        // Replaying the original queue job cannot resend accepted or not-yet-due entries.
        $sender->send($client, [$ids], $acquire);
        self::assertCount(1, $calls);
        $em->getConnection()->executeStatement('UPDATE ses_bulk_deliveries SET next_attempt = 0');
        $sender->send($client, [$ids], $acquire);
        self::assertSame([2, 1], $tokens);
        self::assertCount(1, $calls[1]['BulkEmailEntries']);
        self::assertSame(0, $calls[0]['@retries']);
        self::assertSame('accepted', $store->summary()['recipients'][0]['state']);
    }

    public function testTimeoutIsUnknownAndNotAutomaticallyRetried(): void
    {
        $calls = 0;
        $client = self::client(function ($command) use (&$calls) {
            ++$calls;
            return Create::rejectionFor(new AwsException('Connection lost', $command, ['connection_error' => true]));
        });
        $store = new DeliveryStore(DeliveryStoreTest::manager());
        $store->install();
        $id = $store->enqueue(DeliveryStoreTest::delivery(), BulkSender::scope($client));
        $sender = new BulkSender($store, new NullLogger());
        $sender->send($client, [[$id]], static fn () => null);
        $sender->send($client, [[$id]], static fn () => null);
        self::assertSame(1, $calls);
        self::assertSame('unknown', $store->summary()['recipients'][0]['state']);
    }

    public function testMalformedResponseCannotShiftRecipientResults(): void
    {
        $client = self::client(static fn () => Create::promiseFor(new Result(['BulkEmailEntryResults' => [['Status' => 'SUCCESS', 'MessageId' => 'one']]])));
        $store = new DeliveryStore(DeliveryStoreTest::manager());
        $store->install();
        $ids = [$store->enqueue(DeliveryStoreTest::delivery('a'), BulkSender::scope($client)), $store->enqueue(DeliveryStoreTest::delivery('b'), BulkSender::scope($client))];
        (new BulkSender($store, new NullLogger()))->send($client, [$ids], static fn () => null);
        self::assertSame('unknown', $store->summary()['recipients'][0]['state']);
        self::assertSame(2, (int) $store->summary()['recipients'][0]['recipients']);
    }

    public function testWindowLimitsInFlightRequests(): void
    {
        $resolvedAtCall = $this->sendThreeGroups(2);
        // Requests within a window overlap; the next window starts after every prior response is recorded.
        self::assertSame([0, 0, 2], $resolvedAtCall);

        // Without a window each request is submitted only after the previous one resolved.
        self::assertSame([0, 1, 2], $this->sendThreeGroups(1));
    }

    public function testQuotaNeverWaitsWhileAnEarlierWindowHasUnsentOrUnsettledRequests(): void
    {
        $outstanding = 0;
        $tokens = [];
        $client = self::client(static function () use (&$outstanding): Promise {
            ++$outstanding;
            $promise = new Promise(static function () use (&$promise, &$outstanding): void {
                --$outstanding;
                $promise->resolve(new Result(['BulkEmailEntryResults' => [['Status' => 'SUCCESS', 'MessageId' => 'id']]]));
            });

            return $promise;
        });
        $store = new DeliveryStore(DeliveryStoreTest::manager());
        $store->install();
        $ids = self::enqueueGroups($store, $client, ['a', 'b', 'c']);
        $acquire = static function (int $recipients) use (&$outstanding, &$tokens): void {
            self::assertSame(0, $outstanding, 'Quota waiting would delay SDK dispatch of an earlier reserved request.');
            $tokens[] = $recipients;
        };

        (new BulkSender($store, new NullLogger()))->send($client, array_map(static fn (string $id): array => [$id], $ids), $acquire, 2);

        self::assertSame([2, 1], $tokens);
        self::assertSame(0, $outstanding);
        self::assertSame([['accepted', 3]], array_map(static fn (array $row): array => [$row['state'], (int) $row['recipients']], $store->summary()['recipients']));
    }

    public function testFailuresAreAttributedToTheirOwnGroup(): void
    {
        $client = self::client(static function ($command) {
            if ('News a' === $command['DefaultContent']['Template']['TemplateContent']['Subject']) {
                return Create::rejectionFor(new AwsException('Slow down', $command, ['code' => 'TooManyRequestsException']));
            }

            return Create::promiseFor(new Result(['BulkEmailEntryResults' => [['Status' => 'SUCCESS', 'MessageId' => 'b-id']]]));
        });
        $em = DeliveryStoreTest::manager();
        $store = new DeliveryStore($em);
        $store->install();
        $ids = self::enqueueGroups($store, $client, ['a', 'b']);
        (new BulkSender($store, new NullLogger()))->send($client, [$ids], static fn () => null, 2);
        self::assertSame('retry', self::state($em, $ids[0]));
        self::assertSame('accepted', self::state($em, $ids[1]));
    }

    /**
     * @dataProvider localFailures
     */
    public function testPreflightFailureStillRecordsSubmittedRequests(string $step, string $message, array $states, array $attempts): void
    {
        $client = self::client(static fn () => Create::promiseFor(new Result(['BulkEmailEntryResults' => [['Status' => 'SUCCESS', 'MessageId' => 'a-id']]])));
        $em = DeliveryStoreTest::manager();
        $store = new DeliveryStore($em);
        $store->install();
        $ids = self::enqueueGroups($store, $client, ['a', 'b', 'c']);
        $calls = 0;
        $acquire = static function () use (&$calls, $step): void {
            if ('acquire' === $step && 2 === ++$calls) {
                throw new \RuntimeException('Token bucket unavailable');
            }
        };
        if ('content' === $step) {
            $em->getConnection()->executeStatement('DELETE FROM ses_bulk_contents WHERE id = (SELECT content_id FROM ses_bulk_deliveries WHERE id = ?)', [$ids[1]]);
        }
        try {
            (new BulkSender($store, new NullLogger()))->send($client, [$ids], $acquire, 2);
            self::fail('The local failure must propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame($message, $e->getMessage());
        }
        // Completed windows remain recorded; every claimed recipient in an unsubmitted window is released.
        self::assertSame($states, array_map(static fn (string $id): string => self::state($em, $id), $ids));
        // Nothing of the released groups reached SES, so their claims do not count as attempts.
        self::assertSame($attempts, array_map(static fn (string $id): int => (int) $em->getConnection()->fetchOne('SELECT attempts FROM ses_bulk_deliveries WHERE id = ?', [$id]), $ids));
    }

    public function testLocalFailuresDoNotSpendAttempts(): void
    {
        $calls = 0;
        $client = self::client(static function () use (&$calls) {
            ++$calls;

            return Create::promiseFor(new Result(['BulkEmailEntryResults' => [['Status' => 'SUCCESS', 'MessageId' => 'id']]]));
        });
        $em = DeliveryStoreTest::manager();
        $store = new DeliveryStore($em);
        $store->install();
        $id = $store->enqueue(DeliveryStoreTest::delivery(), BulkSender::scope($client));
        $sender = new BulkSender($store, new NullLogger());
        // More consecutive failures than a row has attempts, as when every retry run finds the cache directory unwritable.
        for ($run = 1; $run <= 5; ++$run) {
            try {
                $sender->send($client, [[$id]], static function (): void {
                    throw new \RuntimeException('Token bucket unavailable');
                });
                self::fail('The local failure must propagate.');
            } catch (\RuntimeException $e) {
                self::assertSame('Token bucket unavailable', $e->getMessage());
            }
            $row = $em->getConnection()->fetchAssociative('SELECT state, reason, attempts FROM ses_bulk_deliveries');
            self::assertSame(['retry', 'local_preflight_failure', 0], [$row['state'], $row['reason'], (int) $row['attempts']]);
            $em->getConnection()->executeStatement('UPDATE ses_bulk_deliveries SET next_attempt = 0');
        }
        self::assertSame(0, $calls);

        $sender->send($client, [[$id]], static fn () => null);
        self::assertSame(1, $calls);
        self::assertSame('accepted', self::state($em, $id));
    }

    public function testBookkeepingFailureInsideAWindowSettlesSubmittedRowsAndReleasesTheRest(): void
    {
        $calls = 0;
        $client = self::client(static function () use (&$calls) {
            ++$calls;

            return Create::promiseFor(new Result(['BulkEmailEntryResults' => [['Status' => 'SUCCESS', 'MessageId' => 'id']]]));
        });
        $em = DeliveryStoreTest::manager();
        $store = new DeliveryStore($em);
        $store->install();
        $scope = BulkSender::scope($client);
        $ids = array_map(static fn (string $name): string => $store->enqueue(DeliveryStoreTest::delivery($name), $scope), ['a', 'b', 'c']);
        $em->getConnection()->executeStatement("CREATE TRIGGER fail_second BEFORE UPDATE OF requests ON ses_bulk_contents WHEN NEW.requests = 2 BEGIN SELECT RAISE(ABORT, 'Request bookkeeping failed'); END");

        try {
            (new BulkSender($store, new NullLogger()))->send($client, array_map(static fn (string $id): array => [$id], $ids), static fn () => null, 3);
            self::fail('The local failure must propagate.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('Request bookkeeping failed', $e->getMessage());
        }

        self::assertSame(1, $calls);
        self::assertSame(['accepted', 'retry', 'retry'], array_map(static fn (string $id): string => self::state($em, $id), $ids));
        self::assertSame([1, 0, 0], array_map(static fn (string $id): int => (int) $em->getConnection()->fetchOne('SELECT attempts FROM ses_bulk_deliveries WHERE id = ?', [$id]), $ids));
    }

    public function testDailyQuotaIsRetriedHourlyWithoutSpendingAttempts(): void
    {
        $client = self::client(static fn () => Create::promiseFor(new Result(['BulkEmailEntryResults' => [['Status' => 'ACCOUNT_DAILY_QUOTA_EXCEEDED', 'Error' => 'Daily quota']]])));
        $em = DeliveryStoreTest::manager();
        $db = $em->getConnection();
        $store = new DeliveryStore($em);
        $store->install();
        $id = $store->enqueue(DeliveryStoreTest::delivery(), BulkSender::scope($client));
        $sender = new BulkSender($store, new NullLogger());
        for ($run = 1; $run <= 5; ++$run) {
            $sender->send($client, [[$id]], static fn () => null);
            $row = $db->fetchAssociative('SELECT state, reason, attempts, next_attempt FROM ses_bulk_deliveries');
            self::assertSame(['retry', 'ACCOUNT_DAILY_QUOTA_EXCEEDED', 0], [$row['state'], $row['reason'], (int) $row['attempts']]);
            self::assertGreaterThanOrEqual(time() + 3599, (int) $row['next_attempt']);
            $db->executeStatement('UPDATE ses_bulk_deliveries SET next_attempt = 0');
        }

        // Past the deferral window the quota is an ordinary retryable failure again.
        $db->executeStatement('UPDATE ses_bulk_deliveries SET created_at = ?', [time() - BulkSender::QUOTA_DEFERRAL - 1]);
        $sender->send($client, [[$id]], static fn () => null);
        $row = $db->fetchAssociative('SELECT state, attempts, next_attempt FROM ses_bulk_deliveries');
        self::assertSame(['retry', 1], [$row['state'], (int) $row['attempts']]);
        self::assertLessThanOrEqual(time() + 60, (int) $row['next_attempt']);
    }

    /**
     * @dataProvider loggedOutcomes
     */
    public function testRecipientsThatNeedAttentionAreLoggedAsErrors(callable $respond, string $level, array $outcomes): void
    {
        $client = self::client(static fn ($command) => $respond($command));
        $store = new DeliveryStore(DeliveryStoreTest::manager());
        $store->install();
        $id = $store->enqueue(DeliveryStoreTest::delivery(), BulkSender::scope($client));
        $logger = new class() extends AbstractLogger {
            public array $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = [$level, $context];
            }
        };
        (new BulkSender($store, $logger))->send($client, [[$id]], static fn () => null);

        self::assertCount(1, $logger->records);
        [$logged, $context] = $logger->records[0];
        self::assertSame($level, $logged);
        self::assertSame([42, 1, $outcomes], [$context['email_id'], $context['recipients'], $context['outcomes']]);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $context['content_id']);
    }

    public static function loggedOutcomes(): array
    {
        $entry = static fn (array $result): callable => static fn () => Create::promiseFor(new Result(['BulkEmailEntryResults' => [$result]]));

        return [
            'accepted' => [$entry(['Status' => 'SUCCESS', 'MessageId' => 'id']), LogLevel::INFO, ['accepted' => ['' => 1]]],
            'retry' => [$entry(['Status' => 'TRANSIENT_FAILURE']), LogLevel::WARNING, ['retry' => ['TRANSIENT_FAILURE' => 1]]],
            'rejected' => [$entry(['Status' => 'MESSAGE_REJECTED']), LogLevel::ERROR, ['rejected' => ['MESSAGE_REJECTED' => 1]]],
            'request failed' => [static fn ($command) => Create::rejectionFor(new AwsException('Internal error', $command, ['code' => 'InternalFailure'])), LogLevel::ERROR, ['unknown' => ['ambiguous_request_failure' => 1]]],
        ];
    }

    public static function localFailures(): array
    {
        return [
            'token bucket'    => ['acquire', 'Token bucket unavailable', ['accepted', 'accepted', 'retry'], [1, 1, 0]],
            'missing content' => ['content', 'Missing persisted SES content.', ['retry', 'retry', 'retry'], [0, 0, 0]],
        ];
    }

    public function testRawDeliveryMadeFinalSinceItsClaimIsNotSubmitted(): void
    {
        $em = DeliveryStoreTest::manager();
        $store = new DeliveryStore($em);
        $store->install();
        $b = hash('sha256', 'b');
        $calls = [];
        // The SDK reaches SES once the sender waits for the first response, which happens after the second claim. An SNS
        // event makes that second delivery final before its request is built.
        $client = self::client(static function ($command) use ($store, $b, &$calls) {
            $calls[] = array_column($command['EmailTags'], 'Value', 'Name')['mautic_delivery_id'];
            $store->recordEvent(['mail' => ['messageId' => 'ses-b', 'tags' => ['mautic_delivery_id' => [$b]]]], 'Delivery');

            return Create::promiseFor(new Result(['MessageId' => 'ses-'.count($calls)]));
        });
        $scope = BulkSender::scope($client);
        $a = $store->enqueue(DeliveryStoreTest::raw('a'), $scope);
        $store->enqueue(DeliveryStoreTest::raw('b'), $scope);

        (new BulkSender($store, new NullLogger()))->send($client, [[$a], [$b]], static fn () => null, 1);
        self::assertSame([$a], $calls);
        $rows = $em->getConnection()->fetchAllAssociativeIndexed('SELECT id, state, attempts, message_id FROM ses_bulk_deliveries');
        self::assertSame(['accepted', 1, 'ses-1'], [$rows[$a]['state'], (int) $rows[$a]['attempts'], $rows[$a]['message_id']]);
        // One attempt: the claim came before the event.
        self::assertSame(['accepted', 1, 'ses-b'], [$rows[$b]['state'], (int) $rows[$b]['attempts'], $rows[$b]['message_id']]);
    }

    public function testBatchesWithTheSameContentAreNeverMerged(): void
    {
        $entries = [];
        $client = self::client(static function ($command) use (&$entries) {
            $entries[] = count($command['BulkEmailEntries']);

            return Create::promiseFor(new Result(['BulkEmailEntryResults' => array_fill(0, count($command['BulkEmailEntries']), ['Status' => 'SUCCESS', 'MessageId' => 'id'])]));
        });
        $store = new DeliveryStore(DeliveryStoreTest::manager());
        $store->install();
        $scope = BulkSender::scope($client);
        [$a, $b, $c] = array_map(static fn (string $name): string => $store->enqueue(DeliveryStoreTest::delivery($name), $scope), ['a', 'b', 'c']);
        (new BulkSender($store, new NullLogger()))->send($client, [[$a, $b], [$c]], static fn () => null, 2);
        self::assertSame([2, 1], $entries);
        self::assertSame([['accepted', 3]], array_map(static fn (array $row): array => [$row['state'], (int) $row['recipients']], $store->summary()['recipients']));
    }

    public static function client(callable $handler, string $accessKeyId = 'test'): SesV2Client
    {
        return new SesV2Client(['version' => 'latest', 'region' => 'eu-central-1', 'credentials' => ['key' => $accessKeyId, 'secret' => 'test'], 'handler' => $handler]);
    }

    /** @return list<int> the number of resolved requests when each request reached SES */
    private function sendThreeGroups(int $concurrency): array
    {
        $promises = [];
        $resolved = 0;
        $resolvedAtCall = [];
        // Waiting on any request completes the oldest unresolved one, like responses arriving in order.
        $wait = static function () use (&$promises, &$resolved): void {
            $promises[$resolved]->resolve(new Result(['BulkEmailEntryResults' => [['Status' => 'SUCCESS', 'MessageId' => 'id-'.$resolved]]]));
            ++$resolved;
        };
        $client = self::client(static function () use (&$promises, &$resolved, &$resolvedAtCall, $wait): Promise {
            $resolvedAtCall[] = $resolved;

            return $promises[] = new Promise($wait);
        });
        $store = new DeliveryStore(DeliveryStoreTest::manager());
        $store->install();
        $ids = self::enqueueGroups($store, $client, ['a', 'b', 'c']);
        (new BulkSender($store, new NullLogger()))->send($client, [$ids], static fn () => null, $concurrency);
        self::assertSame([['accepted', 3]], array_map(static fn (array $row): array => [$row['state'], (int) $row['recipients']], $store->summary()['recipients']));

        return $resolvedAtCall;
    }

    /** @return list<string> one delivery per name, each with its own common content and therefore its own request */
    private static function enqueueGroups(DeliveryStore $store, SesV2Client $client, array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $delivery = DeliveryStoreTest::delivery($name);
            $delivery['common']['DefaultContent']['Template']['TemplateContent']['Subject'] = 'News '.$name;
            $ids[] = $store->enqueue($delivery, BulkSender::scope($client));
        }

        return $ids;
    }

    private static function state(EntityManager $em, string $id): string
    {
        return $em->getConnection()->fetchOne('SELECT state FROM ses_bulk_deliveries WHERE id = ?', [$id]);
    }
}
