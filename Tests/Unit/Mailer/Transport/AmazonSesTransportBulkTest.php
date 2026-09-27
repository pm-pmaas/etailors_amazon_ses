<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Tests\Unit\Mailer\Transport;

use Aws\Result;
use Aws\SesV2\SesV2Client;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Promise;
use Mautic\CoreBundle\Helper\PathsHelper;
use Mautic\EmailBundle\Mailer\Message\MauticMessage;
use MauticPlugin\AmazonSesBundle\Mailer\Bulk\BulkSender;
use MauticPlugin\AmazonSesBundle\Mailer\Bulk\DeliveryStore;
use MauticPlugin\AmazonSesBundle\Mailer\Transport\AmazonSesTransport;
use MauticPlugin\AmazonSesBundle\Tests\Unit\Mailer\Bulk\BulkSenderTest;
use MauticPlugin\AmazonSesBundle\Tests\Unit\Mailer\Bulk\DeliveryStoreTest;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;

class AmazonSesTransportBulkTest extends TestCase
{
    private const A = 'a@example.test';
    private const B = 'b@example.test';

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $calls = [];
    private SesV2Client $client;
    private EntityManager $em;
    private DeliveryStore $store;
    private string $cache;

    protected function setUp(): void
    {
        $this->client = $this->acceptingClient();
        $this->em = DeliveryStoreTest::manager();
        $this->store = new DeliveryStore($this->em);
        $this->store->install();
        $this->cache = sys_get_temp_dir().'/ses-transport-test-'.bin2hex(random_bytes(8));
        mkdir($this->cache);
    }

    /** SES accepts every request, as the same account whatever the access key. */
    private function acceptingClient(string $accessKeyId = 'test'): SesV2Client
    {
        return BulkSenderTest::client(function ($command) {
            $this->calls[] = [$command->getName(), $command->toArray()];
            if ('SendBulkEmail' !== $command->getName()) {
                return Create::promiseFor(new Result(['MessageId' => 'raw-'.count($this->calls)]));
            }
            $results = [];
            foreach (array_keys($command['BulkEmailEntries']) as $i) {
                $results[] = ['Status' => 'SUCCESS', 'MessageId' => 'bulk-'.count($this->calls).'-'.$i];
            }

            return Create::promiseFor(new Result(['BulkEmailEntryResults' => $results]));
        }, $accessKeyId);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->cache.'/*') ?: []);
        rmdir($this->cache);
    }

    public function testEligibleMessageIsSubmittedAsOneSharedTemplateRequest(): void
    {
        $message = $this->message();
        $sent = $this->transport()->send($message);

        self::assertSame(['SendBulkEmail'], array_column($this->calls, 0));
        $request = $this->calls[0][1];
        self::assertSame('newsletter', $request['ConfigurationSetName']);
        self::assertArrayNotHasKey('EmailTags', $request);
        $html = $request['DefaultContent']['Template']['TemplateContent']['Html'];
        self::assertStringContainsString('{{v_', $html);
        self::assertStringNotContainsString(self::A, $html);
        self::assertStringNotContainsString(self::B, $html);
        self::assertSame('{}', $request['DefaultContent']['Template']['TemplateData']);
        self::assertCount(2, $request['BulkEmailEntries']);

        $entry = $request['BulkEmailEntries'][0];
        self::assertSame([(new Address(self::A, 'Reader A'))->toString()], $entry['Destination']['ToAddresses']);
        $data = json_decode($entry['ReplacementEmailContent']['ReplacementTemplate']['ReplacementTemplateData'], true, 512, JSON_THROW_ON_ERROR);
        self::assertContains(self::A, $data);
        self::assertContains(self::tokens('a')['{unsubscribe_text}'], $data);
        self::assertNotContains(self::B, $data);
        self::assertContains(['Name' => 'List-Unsubscribe', 'Value' => '<'.self::tokens('a')['{unsubscribe_url}'].'>'], $entry['ReplacementHeaders']);
        self::assertNotContains('X-SES-CONFIGURATION-SET', array_column($entry['ReplacementHeaders'], 'Name'));
        self::assertContains(['Name' => 'X-EMAIL-ID', 'Value' => '42'], $entry['ReplacementTags']);
        $ids = array_map(static fn (array $entry): string => array_column($entry['ReplacementTags'], 'Value', 'Name')['mautic_delivery_id'], $request['BulkEmailEntries']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $ids[0]);
        self::assertNotSame($ids[0], $ids[1]);

        self::assertSame([['bulk', 'accepted', '', 2]], $this->recipients());
        // The message the transport worked on keeps its tokens and metadata.
        $original = $sent->getOriginalMessage();
        self::assertSame($message->getMetadata(), $original->getMetadata());
        self::assertStringContainsString('{contactfield=email}', $original->getHtmlBody());
    }

    public function testAttachmentFallsBackToConcurrentRawPath(): void
    {
        $this->transport()->send($this->message()->attach('data', 'file.txt'));

        self::assertSame(['SendEmail', 'SendEmail'], array_column($this->calls, 0));
        $recipients = array_map(
            static fn (array $call): array => array_values(array_filter([self::A, self::B], static fn (string $email): bool => str_contains($call[1]['Content']['Raw']['Data'], '<'.$email.'>'))),
            $this->calls
        );
        sort($recipients);
        self::assertSame([[self::A], [self::B]], $recipients);
        self::assertSame([], $this->recipients());
    }

    public function testSenderHeaderEqualToFromKeepsTheBulkPath(): void
    {
        // Mautic 7 sets Sender to the From address on every message. The address is compared case-insensitively.
        $this->transport()->send($this->message()->sender(new Address('NEWS@example.test', 'Mautic')));

        self::assertSame(['SendBulkEmail'], array_column($this->calls, 0));
        $entries = $this->calls[0][1]['BulkEmailEntries'];
        self::assertCount(2, $entries);
        foreach ($entries as $entry) {
            self::assertNotContains('sender', array_map('strtolower', array_column($entry['ReplacementHeaders'], 'Name')));
        }
        self::assertSame([['bulk', 'accepted', '', 2]], $this->recipients());
    }

    public function testSenderHeaderDifferentFromFromFallsBackToRaw(): void
    {
        $this->transport()->send($this->message()->sender('other@example.test'));

        self::assertSame(['SendEmail', 'SendEmail'], array_column($this->calls, 0));
        foreach ($this->calls as [, $request]) {
            self::assertMatchesRegularExpression('/^Sender: .*other@example\.test/m', $request['Content']['Raw']['Data']);
        }
        self::assertSame([], $this->recipients());
    }

    public function testRecipientWithoutDeliveryIdentityUsesRawPath(): void
    {
        $message = $this->message();
        // Mautic always sets the key; it is null when no tracking hash exists.
        $message->addMetadata(self::B, ['hashId' => null] + $message->getMetadata()[self::B]);
        $this->transport()->send($message);

        self::assertSame(['SendEmail', 'SendEmail'], array_column($this->calls, 0));
        self::assertSame([], $this->recipients());
    }

    public function testBulkOffKeepsRawPath(): void
    {
        $this->transport(['bulk' => 'off'], false)->send($this->message());

        self::assertSame(['SendEmail', 'SendEmail'], array_column($this->calls, 0));
    }

    public function testPerRecipientIneligibilityUsesPersistedRawFallback(): void
    {
        $this->transport()->send($this->message(['{unsubscribe_text}' => '<a href="https://example.test/u/b">{{Unsubscribe}}</a>']));

        self::assertSame(['SendBulkEmail', 'SendEmail'], array_column($this->calls, 0));
        self::assertCount(1, $this->calls[0][1]['BulkEmailEntries']);
        self::assertStringContainsString('<'.self::B.'>', $this->calls[1][1]['Content']['Raw']['Data']);
        self::assertSame([['bulk', 'accepted', '', 1], ['raw', 'accepted', 'literal_template_delimiters', 1]], $this->recipients());
    }

    public function testRetryBulkResubmitsOnlyDueDeliveries(): void
    {
        $scope = BulkSender::scope($this->client);
        $due = $this->store->enqueue(DeliveryStoreTest::delivery('due'), $scope);
        $done = $this->store->enqueue(DeliveryStoreTest::delivery('done'), $scope);
        $this->store->complete($this->store->claim($done, $scope, 'worker'), 'accepted', '', 'ses-done');
        $raw = $this->store->enqueue(DeliveryStoreTest::raw('raw done'), $scope);
        $this->store->complete($this->store->claim($raw, $scope, 'worker'), 'accepted', '', 'ses-raw');
        // Final rows keep neither their entry nor their raw content, and retryBulk() never reads them.
        self::assertSame(['', ''], $this->em->getConnection()->fetchFirstColumn("SELECT entry FROM ses_bulk_deliveries WHERE state = 'accepted'"));
        self::assertSame('', $this->em->getConnection()->fetchOne("SELECT payload FROM ses_bulk_contents WHERE operation = 'raw'"));
        $this->em->getConnection()->executeStatement('UPDATE ses_bulk_deliveries SET next_attempt = 0');

        self::assertSame(1, $this->transport()->retryBulk());
        self::assertSame(['SendBulkEmail'], array_column($this->calls, 0));
        $entries = $this->calls[0][1]['BulkEmailEntries'];
        self::assertCount(1, $entries);
        self::assertSame($due, array_column($entries[0]['ReplacementTags'], 'Value', 'Name')['mautic_delivery_id']);
    }

    public function testRotatedAccessKeyKeepsTheOutbox(): void
    {
        $this->client = $this->acceptingClient('AKIAOLD');
        $queued = serialize($this->message());
        $this->transport()->send(unserialize($queued));
        $waiting = $this->store->enqueue(DeliveryStoreTest::delivery('waiting'), BulkSender::scope($this->client));
        $this->em->getConnection()->update('ses_bulk_deliveries', ['state' => 'retry', 'attempts' => 1, 'next_attempt' => 0], ['id' => $waiting]);

        // A routine key rotation while the queue message can still be redelivered (messenger:failed:retry).
        $this->client = $this->acceptingClient('AKIANEW');
        $this->transport()->send(unserialize($queued));
        self::assertSame(['SendBulkEmail'], array_column($this->calls, 0));
        self::assertSame(1, $this->transport()->retryBulk());

        self::assertSame(['SendBulkEmail', 'SendBulkEmail'], array_column($this->calls, 0));
        self::assertSame($waiting, array_column($this->calls[1][1]['BulkEmailEntries'][0]['ReplacementTags'], 'Value', 'Name')['mautic_delivery_id']);
        self::assertSame(3, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM ses_bulk_deliveries WHERE state = 'accepted'"));
    }

    public function testRetryBulkSkipsRawDeliveriesMadeFinalElsewhereSinceTheyWereListed(): void
    {
        $log = DeliveryStoreTest::sqlLog();
        $this->em = DeliveryStoreTest::manager([new Middleware($log)]);
        $this->store = new DeliveryStore($this->em);
        $this->store->install();
        $scope = BulkSender::scope($this->client);
        $ids = array_map(fn (string $name): string => $this->store->enqueue(DeliveryStoreTest::raw($name), $scope), ['raw a', 'raw b', 'raw c']);
        $db = $this->em->getConnection();
        $other = new DeliveryStore($this->em);
        $completedElsewhere = null;
        $this->client = BulkSenderTest::client(function ($command) use ($db, $other, $scope, &$completedElsewhere) {
            $this->calls[] = [$command->getName(), $command->toArray()];
            // When the first request reaches SES, another process completes the one delivery retryBulk() has not read yet.
            if (null === $completedElsewhere) {
                $completedElsewhere = $db->fetchOne("SELECT id FROM ses_bulk_deliveries WHERE state = 'pending' ORDER BY content_id, created_at, id");
                $other->complete($other->claim($completedElsewhere, $scope, 'other-worker'), 'accepted', '', 'ses-other');
            }

            return Create::promiseFor(new Result(['MessageId' => 'raw-'.count($this->calls)]));
        });

        // A window of one: the SDK reaches SES only once the sender waits, and by then the second delivery is read.
        self::assertSame(3, $this->transport(['bulkConcurrency' => 1])->retryBulk());
        self::assertNotFalse($completedElsewhere);
        $submitted = array_map(static fn (array $call): string => array_column($call[1]['EmailTags'], 'Value', 'Name')['mautic_delivery_id'], $this->calls);
        self::assertEqualsCanonicalizing(array_values(array_diff($ids, [$completedElsewhere])), $submitted);
        self::assertSame(['SendEmail', 'SendEmail'], array_column($this->calls, 0));
        // Two claims by retryBulk() and one by the other process: the delivery read as final is not claimed.
        self::assertCount(3, preg_grep("/^UPDATE ses_bulk_deliveries SET state = 'sending'/", $log->sql));
        self::assertSame([['raw', 'accepted', '', 3]], $this->recipients());
    }

    /**
     * @dataProvider windows
     */
    public function testRequestsOfOneMessageArePipelined(int $concurrency, array $expected): void
    {
        $answeredAtCall = $this->deferResponses();
        // One recipient per request, so the two recipients need two requests.
        $this->transport(['bulkBatchSize' => 1, 'bulkConcurrency' => $concurrency])->send($this->message());

        self::assertSame(['SendBulkEmail', 'SendBulkEmail'], array_column($this->calls, 0));
        self::assertSame($expected, $answeredAtCall->getArrayCopy());
        self::assertSame([['bulk', 'accepted', '', 2]], $this->recipients());
    }

    public static function windows(): array
    {
        return [
            // The second request reaches SES before the first response arrives.
            'window of two' => [2, [0, 0]],
            // Without a window the second request waits for the first response.
            'window of one' => [1, [0, 1]],
        ];
    }

    public function testOutboxRetryIsPipelined(): void
    {
        $answeredAtCall = $this->deferResponses();
        $scope = BulkSender::scope($this->client);
        $this->store->enqueue(DeliveryStoreTest::delivery('a'), $scope);
        $this->store->enqueue(DeliveryStoreTest::delivery('b'), $scope);

        self::assertSame(2, $this->transport(['bulkBatchSize' => 1, 'bulkConcurrency' => 2])->retryBulk());
        self::assertSame(['SendBulkEmail', 'SendBulkEmail'], array_column($this->calls, 0));
        self::assertSame([0, 0], $answeredAtCall->getArrayCopy());
        self::assertSame([['bulk', 'accepted', '', 2]], $this->recipients());
    }

    public function testFailureBeforeTheCommitReachesMauticAndLeavesNothingBehind(): void
    {
        // Saving the second recipient fails, for example with a lock wait timeout.
        $this->em->getConnection()->executeStatement("CREATE TRIGGER fail_b BEFORE INSERT ON ses_bulk_deliveries WHEN NEW.tracking_hash = 'hash-b' BEGIN SELECT RAISE(ABORT, 'Lock wait timeout exceeded'); END");

        // Symfony throttles a failed send twice, which would wait a second at the transport's one message per second.
        $transport = $this->transport()->setMaxPerSecond(0);
        try {
            $transport->send($this->message());
            self::fail('Mautic must learn that the message failed.');
        } catch (TransportException $e) {
            self::assertStringContainsString('Lock wait timeout exceeded', $e->getMessage());
        }
        // Nothing was submitted and nothing is left that the retry command would send next to Mautic's own resend.
        self::assertSame([], $this->calls);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ses_bulk_deliveries'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ses_bulk_contents'));
    }

    /**
     * @dataProvider failuresAfterSubmission
     */
    public function testFailureAfterTheFirstRequestIsLeftToTheOutbox(string $trigger, array $operations, array $stateB): void
    {
        $this->em->getConnection()->executeStatement($trigger);
        $logger = new class() extends AbstractLogger {
            public array $errors = [];

            public function log($level, $message, array $context = []): void
            {
                if (LogLevel::ERROR === $level) {
                    $this->errors[] = (string) $message;
                }
            }
        };

        // Two requests, one at a time: the first is accepted before the second batch fails. Mautic keeps its statistics,
        // so it will not send the message again under new tracking hashes.
        $this->transport(['bulkBatchSize' => 1, 'bulkConcurrency' => 1], true, $logger)->send($this->message());

        self::assertSame(['SES bulk sending stopped after the recipients were saved; mautic:ses:bulk retry submits the ones left.'], $logger->errors);
        self::assertSame($operations, array_column($this->calls, 0));
        $rows = $this->em->getConnection()->fetchAllAssociativeIndexed('SELECT tracking_hash, state, reason, attempts FROM ses_bulk_deliveries');
        self::assertSame('accepted', $rows['hash-a']['state']);
        self::assertSame($stateB, [$rows['hash-b']['state'], $rows['hash-b']['reason'], (int) $rows['hash-b']['attempts']]);
    }

    public static function failuresAfterSubmission(): array
    {
        return [
            // Never submitted: released for the retry command without spending an attempt.
            'before the second request' => ["CREATE TRIGGER fail_b BEFORE UPDATE OF requests ON ses_bulk_contents WHEN NEW.requests = 2 BEGIN SELECT RAISE(ABORT, 'Lock wait timeout exceeded'); END", ['SendBulkEmail'], ['retry', 'local_preflight_failure', 0]],
            // Submitted, outcome not recorded: the claim expires to unknown and is never sent again.
            'recording the second outcome' => ["CREATE TRIGGER fail_b BEFORE UPDATE ON ses_bulk_deliveries WHEN OLD.tracking_hash = 'hash-b' AND OLD.state = 'sending' BEGIN SELECT RAISE(ABORT, 'Server has gone away'); END", ['SendBulkEmail', 'SendBulkEmail'], ['sending', '', 1]],
        ];
    }

    public function testTokenizedHeaderThatResolvesToNothingIsLeftOut(): void
    {
        $message = $this->message(['{contactfield=company}' => ''], ['{contactfield=company}' => 'ACME']);
        $message->getHeaders()->addTextHeader('X-Company', '{contactfield=company}');
        $this->transport()->send($message);

        // As Mautic core does for custom headers; SES refuses an empty header value for the whole request.
        self::assertSame(['SendBulkEmail'], array_column($this->calls, 0));
        [$a, $b] = array_map(static fn (array $entry): array => array_column($entry['ReplacementHeaders'], 'Value', 'Name'), $this->calls[0][1]['BulkEmailEntries']);
        self::assertSame('ACME', $a['X-Company']);
        self::assertArrayNotHasKey('X-Company', $b);
        self::assertSame([['bulk', 'accepted', '', 2]], $this->recipients());
    }

    public function testHeaderValueThatSesRefusesSendsThatRecipientRaw(): void
    {
        // Symfony leaves a tab unencoded, but SES only accepts printable ASCII.
        $message = $this->message(['{contactfield=company}' => "Acme\tInc"], ['{contactfield=company}' => 'ACME']);
        $message->getHeaders()->addTextHeader('X-Company', '{contactfield=company}');
        $this->transport()->send($message);

        self::assertSame(['SendBulkEmail', 'SendEmail'], array_column($this->calls, 0));
        self::assertCount(1, $this->calls[0][1]['BulkEmailEntries']);
        self::assertStringContainsString('<'.self::B.'>', $this->calls[1][1]['Content']['Raw']['Data']);
        self::assertSame([['bulk', 'accepted', '', 1], ['raw', 'accepted', 'header_value', 1]], $this->recipients());
    }

    /**
     * @dataProvider feedbackAddresses
     */
    public function testReturnPathIsSentAsFeedbackForwardingAddress(?string $feedbackHeader, array $operations): void
    {
        // Mautic sets Return-Path from the custom return path (mailer_return_path) or a bounce address.
        $message = $this->message()->returnPath('bounces@example.test');
        if (null !== $feedbackHeader) {
            $message->getHeaders()->addTextHeader('X-SES-FEEDBACK-FORWARDING-EMAIL-ADDRESS', $feedbackHeader);
        }
        $this->transport()->send($message);

        self::assertSame($operations, array_column($this->calls, 0));
        foreach ($this->calls as [$operation, $request]) {
            if ('SendBulkEmail' === $operation) {
                self::assertSame('bounces@example.test', $request['FeedbackForwardingEmailAddress']);
                foreach ($request['BulkEmailEntries'] as $entry) {
                    self::assertNotContains('return-path', array_map('strtolower', array_column($entry['ReplacementHeaders'], 'Name')));
                }
            } else {
                self::assertStringContainsString('Return-Path: <bounces@example.test>', $request['Content']['Raw']['Data']);
            }
        }
    }

    public static function feedbackAddresses(): array
    {
        return [
            'return path only' => [null, ['SendBulkEmail']],
            'same feedback address' => ['Bounces@example.test', ['SendBulkEmail']],
            // Which of the two SES would use is not documented, so the email is sent as before.
            'different feedback address' => ['feedback@example.test', ['SendEmail', 'SendEmail']],
        ];
    }

    /**
     * @dataProvider returnPathSends
     */
    public function testReturnPathDoesNotReplaceTheFromAddress(array $tokensB, bool $attachment, array $operations, ?string $fromName, string $name): void
    {
        // Mautic sets Return-Path from mailer_return_path, which Symfony's envelope then uses as the sender. An email
        // without its own From address keeps the one Mautic resolved.
        $message = $this->message($tokensB)->returnPath('bounces@example.test');
        if ($attachment) {
            $message->attach('data', 'file.txt');
        }
        $this->transport(entity: (new \Mautic\EmailBundle\Entity\Email())->setFromName($fromName))->send($message);

        self::assertSame($operations, array_column($this->calls, 0));
        foreach ($this->calls as [$operation, $request]) {
            self::assertSame('"'.$name.'" <news@example.test>', $request['FromEmailAddress']);
            if ('SendEmail' === $operation) {
                self::assertMatchesRegularExpression('/^From: '.$name.' <news@example\.test>\r?$/m', $request['Content']['Raw']['Data']);
            }
        }
    }

    public static function returnPathSends(): array
    {
        $delimiters = ['{unsubscribe_text}' => '<a href="https://example.test/u/b">{{Unsubscribe}}</a>'];

        return [
            'shared template' => [[], false, ['SendBulkEmail'], null, 'Newsroom'],
            'raw fallback of one recipient' => [$delimiters, false, ['SendBulkEmail', 'SendEmail'], null, 'Newsroom'],
            'raw email' => [[], true, ['SendEmail', 'SendEmail'], null, 'Newsroom'],
            'From name of the email' => [[], false, ['SendBulkEmail'], 'Editor', 'Editor'],
        ];
    }

    /**
     * @dataProvider windowLimits
     */
    public function testWindowCarriesAtMostOneSecondOfTheSendRate(int $concurrency, array $entries): void
    {
        // A full bucket, so the test does not wait for tokens.
        file_put_contents($this->cache.'/ses_token_bucket.json', json_encode(['tokens' => 4.0, 'last_time' => microtime(true)]));
        $this->transport(['maxSendRate' => 4, 'bulkConcurrency' => $concurrency])->send($this->message());

        self::assertSame($entries, array_map(static fn (array $call): int => count($call[1]['BulkEmailEntries']), $this->calls));
        self::assertSame([['bulk', 'accepted', '', 2]], $this->recipients());
    }

    public static function windowLimits(): array
    {
        return [
            // Two requests in flight may leave together: 2 x 2 recipients is one second at 4/s.
            'window of two' => [2, [2]],
            // Four requests in flight: one recipient each.
            'window of four' => [4, [1, 1]],
        ];
    }

    private function transport(array $settings = [], bool $bulkServices = true, ?LoggerInterface $logger = null, ?\Mautic\EmailBundle\Entity\Email $entity = null): AmazonSesTransport
    {
        $paths = $this->createMock(PathsHelper::class);
        $paths->method('getSystemPath')->with('cache', true)->willReturn($this->cache);
        // Mautic's EmailRepository requires a ManagerRegistry, so the Email lookup is stubbed (no entity: no From/Reply-To override).
        $emails = $this->createMock(EntityManagerInterface::class);
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->willReturn($entity);
        $emails->method('getRepository')->willReturn($repository);

        return new AmazonSesTransport(
            $this->client,
            $emails,
            $paths,
            new EventDispatcher(),
            $logger ?? new NullLogger(),
            $settings + ['maxSendRate' => 80, 'batchMultiplier' => 10, 'bulk' => 'auto', 'bulkBatchSize' => 50],
            $bulkServices ? $this->store : null,
            $bulkServices ? new BulkSender($this->store, new NullLogger()) : null,
        );
    }

    /**
     * Responses stay open until the sender waits; each wait answers the oldest open request, as if responses arrived in order.
     * Every request is answered with one successful entry, so requests must carry one recipient each.
     *
     * @return \ArrayObject<int, int> for each request, the number of answered requests when it reached SES
     */
    private function deferResponses(): \ArrayObject
    {
        $open = [];
        $answered = 0;
        $answeredAtCall = new \ArrayObject();
        $wait = static function () use (&$open, &$answered): void {
            $open[$answered]->resolve(new Result(['BulkEmailEntryResults' => [['Status' => 'SUCCESS', 'MessageId' => 'bulk-'.$answered]]]));
            ++$answered;
        };
        $this->client = BulkSenderTest::client(function ($command) use (&$open, &$answered, $answeredAtCall, $wait): Promise {
            $this->calls[] = [$command->getName(), $command->toArray()];
            $answeredAtCall[] = $answered;

            return $open[] = new Promise($wait);
        });

        return $answeredAtCall;
    }

    private function message(array $tokensB = [], array $tokensA = []): MauticMessage
    {
        $message = (new MauticMessage())
            ->from(new Address('news@example.test', 'Newsroom'))
            ->to(new Address(self::A, 'Reader A'), new Address(self::B, 'Reader B'))
            ->subject('News')
            ->html('<p>News</p><p>{contactfield=email}</p>{unsubscribe_text}<img src="{tracking_pixel}">')
            ->text('News {contactfield=email}');
        $message->getHeaders()->addTextHeader('X-EMAIL-ID', '42');
        $message->getHeaders()->addTextHeader('X-SES-CONFIGURATION-SET', 'newsletter');
        $message->getHeaders()->addTextHeader('List-Unsubscribe', '<https://example.test/old>');
        $message->addMetadata(self::A, ['name' => 'Reader A', 'emailId' => 42, 'hashId' => 'hash-a', 'tokens' => $tokensA + self::tokens('a')]);
        $message->addMetadata(self::B, ['name' => 'Reader B', 'emailId' => 42, 'hashId' => 'hash-b', 'tokens' => $tokensB + self::tokens('b')]);

        return $message;
    }

    private static function tokens(string $contact): array
    {
        return [
            '{contactfield=email}' => $contact.'@example.test',
            '{unsubscribe_text}' => '<a href="https://example.test/u/'.$contact.'">Unsubscribe '.$contact.'</a>',
            '{unsubscribe_url}' => 'https://example.test/u/'.$contact,
            '{tracking_pixel}' => 'https://example.test/p/'.$contact.'.gif',
        ];
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: int}> operation, state, reason, recipients */
    private function recipients(): array
    {
        $rows = array_map(static fn (array $row): array => [$row['operation'], $row['state'], $row['reason'], (int) $row['recipients']], $this->store->summary(42)['recipients']);
        sort($rows);

        return $rows;
    }

    public function testReplayAfterCustomHeaderChangeDoesNotResendAcceptedRecipient(): void
    {
        $entity = new \Mautic\EmailBundle\Entity\Email();
        $transport = $this->transport(entity: $entity);
        $queued = serialize($this->message());
        $transport->send(unserialize($queued));
        $entity->setHeaders(['Organization' => 'Example']);
        $transport->send(unserialize($queued));

        self::assertSame(['SendBulkEmail'], array_column($this->calls, 0));
        self::assertSame([['bulk', 'accepted', '', 2]], $this->recipients());
    }

    public function testSandboxRateOneSpacesActualDispatch(): void
    {
        $times = [];
        $client = BulkSenderTest::client(static function ($command) use (&$times) {
            $times[] = microtime(true);
            return Create::promiseFor(new Result(['BulkEmailEntryResults' => [['Status' => 'SUCCESS', 'MessageId' => 'ses-id']]]));
        });
        $store = new DeliveryStore(DeliveryStoreTest::manager());
        $store->install();
        $scope = BulkSender::scope($client);
        $ids = array_map(fn ($name) => $store->enqueue(DeliveryStoreTest::delivery($name), $scope), ['a', 'b']);
        $transport = (new \ReflectionClass(AmazonSesTransport::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($transport, 'settings'))->setValue($transport, ['maxSendRate' => 1, 'bulkConcurrency' => 2]);
        [$size, $concurrency] = (new \ReflectionMethod($transport, 'bulkWindow'))->invoke($transport);
        $bucket = tempnam($this->cache, 'rate-review-');
        file_put_contents($bucket, json_encode(['tokens' => 1, 'last_time' => microtime(true)]));
        try {
            $acquire = fn (int $count) => (new \ReflectionMethod($transport, 'acquireTokens'))->invoke($transport, $bucket, $count, 1);
            (new BulkSender($store, new NullLogger()))->send($client, array_chunk($ids, $size), $acquire, $concurrency);
        } finally {
            unlink($bucket);
        }
        self::assertGreaterThan(0.9, $times[1] - $times[0], 'One second of token waiting did not space dispatches.');
    }

    public function testReplayKeepsSavedContentAndOnlySubmitsDueOrMissingRecipients(): void
    {
        $scope = BulkSender::scope($this->client);
        $message = (new MauticMessage())->from('news@example.test')->to('accepted@example.test')->subject('Changed')->html('Changed body');
        $ids = [];
        foreach (['accepted', 'pending', 'due', 'future', 'unknown', 'sending', 'rejected', 'missing'] as $name) {
            $recipient = $name.'@example.test';
            $metadata = ['emailId' => 42, 'hashId' => 'hash-'.$name, 'tokens' => []];
            $message->addMetadata($recipient, $metadata);
            $id = $ids[$name] = hash('sha256', $scope.'|42|hash-'.$name.'|'.$recipient);
            if ('missing' === $name) {
                continue;
            }
            $delivery = DeliveryStoreTest::delivery($name);
            $delivery['id'] = $id;
            $delivery['tracking_hash'] = $metadata['hashId'];
            $delivery['entry']['Destination']['ToAddresses'] = [$recipient];
            $delivery['entry']['ReplacementTags'][0]['Value'] = $id;
            $delivery['common']['DefaultContent']['Template']['TemplateContent']['Subject'] = 'Saved original';
            $this->store->enqueue($delivery, $scope);
            if (in_array($name, ['accepted', 'unknown', 'rejected'], true)) {
                $this->store->complete($this->store->claim($id, $scope, 'first-worker'), $name);
            } elseif ('sending' === $name) {
                $this->store->claim($id, $scope, 'other-worker');
            } elseif (in_array($name, ['due', 'future'], true)) {
                $this->em->getConnection()->update('ses_bulk_deliveries', ['state' => 'retry', 'attempts' => 1, 'next_attempt' => 'future' === $name ? time() + 3600 : 0], ['id' => $id]);
            }
        }
        $unrelated = $this->store->enqueue(DeliveryStoreTest::delivery('unrelated'), $scope);
        $entity = (new \Mautic\EmailBundle\Entity\Email())->setHeaders(['Organization' => 'Changed']);
        $transport = $this->transport(entity: $entity)->setMaxPerSecond(0);
        $transport->send(unserialize(serialize($message)));
        $transport->send(unserialize(serialize($message)));

        self::assertSame(['SendBulkEmail', 'SendEmail'], array_column($this->calls, 0));
        self::assertSame('Saved original', $this->calls[0][1]['DefaultContent']['Template']['TemplateContent']['Subject']);
        self::assertSame([['pending@example.test'], ['due@example.test']], array_column(array_column($this->calls[0][1]['BulkEmailEntries'], 'Destination'), 'ToAddresses'));
        self::assertSame(['missing@example.test'], $this->calls[1][1]['Destination']['ToAddresses']);
        $states = $this->em->getConnection()->fetchAllKeyValue('SELECT id, state FROM ses_bulk_deliveries');
        self::assertSame('retry', $states[$ids['future']]);
        self::assertSame('unknown', $states[$ids['unknown']]);
        self::assertSame('sending', $states[$ids['sending']]);
        self::assertSame('rejected', $states[$ids['rejected']]);
        self::assertSame('pending', $states[$unrelated]);
    }

    /** @dataProvider effectiveWindows */
    public function testEffectiveWindowRespectsRate(int $rate, int $configured, array $expected): void
    {
        $transport = $this->transport(['maxSendRate' => $rate, 'bulkConcurrency' => $configured]);
        self::assertSame($expected, (new \ReflectionMethod($transport, 'bulkWindow'))->invoke($transport));
    }

    public static function effectiveWindows(): array
    {
        return [[1, 10, [1, 1]], [2, 10, [1, 2]], [4, 2, [2, 2]], [80, 2, [40, 2]], [80, 1, [50, 1]]];
    }

    public function testRetryAtRateOneCapsTheConfiguredWindow(): void
    {
        $answered = $this->deferResponses();
        $scope = BulkSender::scope($this->client);
        foreach (['first', 'second'] as $name) {
            $this->store->enqueue(DeliveryStoreTest::delivery($name), $scope);
        }
        file_put_contents($this->cache.'/ses_token_bucket.json', json_encode(['tokens' => 1, 'last_time' => microtime(true)]));
        self::assertSame(2, $this->transport(['maxSendRate' => 1, 'bulkConcurrency' => 10])->retryBulk());
        self::assertSame([0, 1], $answered->getArrayCopy());
    }
}
