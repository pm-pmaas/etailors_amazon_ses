<?php
/*
 * @copyright       (c) 2024. e-tailors IP B.V. All rights reserved
 * @author          Paul Maas <p.maas@e-tailors.com>
 *
 * @link            https://www.e-tailors.com
 */

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Mailer\Transport;

use Aws\CommandPool;
use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\Ses\Exception\SesException;
use Aws\SesV2\SesV2Client;
use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\EmailBundle\Mailer\Message\MauticMessage;
use Mautic\EmailBundle\Mailer\Transport\TokenTransportInterface;
use Mautic\EmailBundle\Mailer\Transport\TokenTransportTrait;
use MauticPlugin\AmazonSesBundle\Helper\MauticEmailId;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Mautic\EmailBundle\Entity\Email as MauticEmailEntity;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\PathsHelper;
use Symfony\Component\Mailer\Envelope;
use MauticPlugin\AmazonSesBundle\Mailer\Bulk\BulkBatcher;
use MauticPlugin\AmazonSesBundle\Mailer\Bulk\BulkSender;
use MauticPlugin\AmazonSesBundle\Mailer\Bulk\DeliveryStore;
use MauticPlugin\AmazonSesBundle\Mailer\Bulk\IneligibleMessage;
use MauticPlugin\AmazonSesBundle\Mailer\Bulk\SharedTemplateCompiler;
use Psr\Log\NullLogger;

class AmazonSesTransport extends AbstractTransport implements TokenTransportInterface
{
    use TokenTransportTrait;

    /**
     * DSN constant.
     */
    public const MAUTIC_AMAZONSES_API_SCHEME = 'mautic+ses+api';

    /**
     * Amazon region constants.
     */
    public const AMAZON_REGION = [
        'us-east-1'      => 'us-east-1',
        'us-east-2'      => 'us-east-2',
        'us-west-2'      => 'us-west-2',
        'af-south-1'     => 'af-south-1',
        'ap-south-1'     => 'ap-south-1',
        'ap-northeast-2' => 'ap-northeast-2',
        'ap-southeast-1' => 'ap-southeast-1',
        'ap-southeast-2' => 'ap-southeast-2',
        'ap-northeast-1' => 'ap-northeast-1',
        'ca-central-1'   => 'ca-central-1',
        'eu-central-1'   => 'eu-central-1',
        'eu-central-2'   => 'eu-central-2',
        'eu-west-1'      => 'eu-west-1',
        'eu-west-2'      => 'eu-west-2',
        'eu-west-3'      => 'eu-west-3',
        'eu-north-1'     => 'eu-north-1',
        'sa-east-1'      => 'sa-east-1',
        'us-gov-west-1'  => 'us-gov-west-1',
        'us-west-1'      => 'us-west-1',
    
    ];

    /**
     *  Header key contstants.
     */
    public const STD_HEADER_KEYS = [
       'MIME-Version',
       'received',
       'dkim-signature',
       'Content-Type',
       'Content-Transfer-Encoding',
       'To',
       'From',
       'Subject',
       'Reply-To',
       'CC',
       'BCC',
    ];

    private $enableTemplate;
    private $entityManager;
    private PathsHelper $pathsHelper;
    private MauticMessage $message;
    private Envelope $envelope;

    private SesV2Client $client;
    private EventDispatcherInterface $dispatcher;
    private LoggerInterface $logger;

    private array $settings;
    private ?SharedTemplateCompiler $compiler = null;

    public function __construct(
        SesV2Client $amazonclient,
        EntityManagerInterface $entityManager,
        PathsHelper $pathsHelper,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
        $settings = [],
        private ?DeliveryStore $deliveryStore = null,
        private ?BulkSender $bulkSender = null,
    ) {
        parent::__construct($dispatcher, $logger);
        $this->logger     = $logger ?? new NullLogger();
        $this->client     = $amazonclient;
        $this->dispatcher = $dispatcher;
        $this->entityManager = $entityManager;
        $this->pathsHelper = $pathsHelper;
        $this->settings   = $settings;

        /*
         * Since symfony/mailer is transactional by default, we need to set the max send rate to 1
         * to avoid sending multiple emails at once.
         * We are getting tokinzed emails, so there will be MaxSendRate emails per call
         * Mailer should process tokinzed emails one by one
         * This transport SHOULD NOT RUN IN PARALLEL.
         */
        $this->setMaxPerSecond(1);
    }

    public function __toString(): string
    {

        try {
            $credentials = $this->getCredentials();
        } catch (\Exception $exception) {
            $credentials = new Credentials('', '');
        }

        $parameters = http_build_query(['region' => $this->client->getRegion()]);

        return sprintf('mautic+ses+api://%s@%s', $credentials->getAccessKeyId(), $parameters);
    }

    protected function doSend(SentMessage $message): void
    {
        $this->logger->debug('inDosendfunction');

        try {
            $email = $message->getOriginalMessage();

            // Ensure the message is an instance of MauticMessage
            if (!$email instanceof MauticMessage) {
                throw new \Exception('Message must be an instance of '.MauticMessage::class);
            }

            $this->message = $email;
            $this->envelope = $message->getEnvelope();

            $scope = null;
            $ids = [];
            if ('auto' === ($this->settings['bulk'] ?? 'off') && $this->canIdentifyRecipients($email)) {
                $scope = BulkSender::scope($this->client);
                foreach ($email->getMetadata() as $recipient => $data) {
                    $ids[] = $this->deliveryId($scope, $recipient, $data);
                }
                $existing = $this->deliveryStore?->existingIds($ids, $scope) ?? [];
                if ($existing) {
                    try {
                        // Replay uses saved content. Only recipients whose rows are missing need current Email fields.
                        if (count($existing) < count($ids)) {
                            $this->updateEmailFields($email);
                        }
                        $this->sendWithBulkAdapter($scope, $ids, $existing);
                    } catch (\Throwable $e) {
                        // Existing ownership must not escape to Mautic's full-message resend under new tracking hashes.
                        $this->logger->error('SES outbox replay stopped; recover saved recipients with mautic:ses:bulk retry.', ['exception' => $e]);
                    }

                    return;
                }
            }

            // Use centralized method for updating From address
            $this->updateEmailFields($email);

            if (null !== $scope) {
                $reason = $this->bulkEligibilityReason($scope);
                if (null === $reason) {
                    $this->sendWithBulkAdapter($scope, $ids);

                    return;
                }
                $this->logger->info('SES raw sending: message is not eligible for shared templates.', ['reason' => $reason, 'email_id' => $this->getEmailIdFromMetadata($email->getMetadata())]);
            }

            $failures = [];

            // Handle attachment or non-template emails
            if ($email->getAttachments() || !$this->enableTemplate) {
                $this->logger->debug('attachments OR NOT template');
                $this->logger->debug('sendrate:' . $this->settings['maxSendRate']);

                $commands = [];
                foreach ($this->convertMessageToRawPayload() as $payload) {
                    $commands[] = $this->client->getCommand('sendEmail', $payload);
                }

                // Send in micro-batches with shared token bucket for cross-worker rate limiting.
                // Lock held only ~30µs (file read/write), NOT during API call.
                // Workers send in parallel — the bucket ensures combined rate across
                // all workers never exceeds the SES limit. Set ratelimit to the full
                // SES account limit (e.g. 70) regardless of worker count.
                $rate = max(1, (int) ($this->settings['maxSendRate'] ?? 14));
                $microBatchSize = max(1, (int) ceil($rate / 10));
                $batches = array_chunk($commands, $microBatchSize);
                $bucketFile = $this->pathsHelper->getSystemPath('cache', true) . '/ses_token_bucket.json';

                foreach ($batches as $bi => $batchCommands) {
                    $batchFailures = [];

                    // Acquire tokens — brief lock (~30µs), then send with NO lock held
                    $this->acquireTokens($bucketFile, count($batchCommands), $rate);

                    $pool = new CommandPool($this->client, $batchCommands, [
                        'concurrency' => count($batchCommands),
                        'fulfilled' => function (Result $result, $iteratorId) {
                        },
                        'rejected' => function (AwsException $reason, $iteratorId) use ($batchCommands, &$batchFailures) {
                            $batchFailures[] = $batchCommands[$iteratorId];
                            $data = $batchCommands[$iteratorId]->toArray();
                            $this->logger->error('Rejected: message to '.implode(',', $data['Destination']['ToAddresses']));
                            $this->logger->error('AWS SES Error: '.$reason->getMessage());
                        },
                    ]);

                    $promise = $pool->promise();
                    $promise->wait();

                    // Inline retry for transient SES failures (network glitch, 429, etc.)
                    if (!empty($batchFailures)) {
                        $initialFailCount = count($batchFailures);
                        $retryDelay = 1000000;
                        for ($attempt = 1; $attempt <= 3 && !empty($batchFailures); $attempt++) {
                            $this->logger->error(sprintf(
                                '%d SES sends failed, inline retry %d/3 after %dms',
                                count($batchFailures), $attempt, $retryDelay / 1000
                            ));
                            usleep($retryDelay);
                            $retryDelay *= 2;

                            $retryCommands = $batchFailures;
                            $batchFailures = [];
                            $this->acquireTokens($bucketFile, count($retryCommands), $rate);
                            $retryPool = new CommandPool($this->client, $retryCommands, [
                                'concurrency' => count($retryCommands),
                                'fulfilled' => function (Result $result, $iteratorId) {
                                },
                                'rejected' => function (AwsException $reason, $iteratorId) use ($retryCommands, &$batchFailures) {
                                    $batchFailures[] = $retryCommands[$iteratorId];
                                    $data = $retryCommands[$iteratorId]->toArray();
                                    $this->logger->error(sprintf(
                                        'Retry rejected: %s — %s',
                                        $data['Destination']['ToAddresses'][0],
                                        $reason->getAwsErrorMessage() ?: $reason->getMessage()
                                    ));
                                },
                            ]);
                            $retryPool->promise()->wait();

                            if (empty($batchFailures)) {
                                $this->logger->error(sprintf(
                                    'All %d recovered on retry %d/3',
                                    count($retryCommands), $attempt
                                ));
                                break;
                            }
                        }

                        $recovered = $initialFailCount - count($batchFailures);
                        if ($recovered > 0) {
                            $this->logger->error(sprintf(
                                '%d/%d recovered by inline retry, %d permanently failed',
                                $recovered, $initialFailCount, count($batchFailures)
                            ));
                        }
                        foreach ($batchFailures as $failedCmd) {
                            $data = $failedCmd->toArray();
                            $failures[] = $data['Destination']['ToAddresses'][0];
                        }
                    }
                }
            }

            $this->processFailures($failures);
        } catch (SesException $exception) {
            $message = $exception->getAwsErrorMessage() ?: $exception->getMessage();
            $code = $exception->getStatusCode() ?: $exception->getCode();
            throw new TransportException(sprintf('Unable to send an email: %s (code %s).', $message, $code));
        } catch (\Exception $exception) {
            $this->logger->info($exception);
            throw new TransportException(sprintf('Unable to send an email: %s .', $exception->getMessage(), $exception->getCode()));
        }
    }

    private function canIdentifyRecipients(MauticMessage $message): bool
    {
        if (!$message->getMetadata()) {
            return false;
        }
        foreach ($message->getMetadata() as $data) {
            if (empty($data['hashId']) || !is_string($data['hashId']) || strlen($data['hashId']) > 191 || empty($data['emailId'])) {
                $this->logger->info('SES raw sending: recipient metadata has no durable delivery identity.');

                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string>        $ids
     * @param array<string, true> $existing already-owned recipients must not be recompiled
     */
    private function sendWithBulkAdapter(string $scope, array $ids, array $existing = []): void
    {
        if (!$this->deliveryStore || !$this->bulkSender) {
            throw new \LogicException('SES bulk services are not configured.');
        }
        $this->deliveryStore->assertInstalled();
        BulkSender::assertSupported($this->client);
        $this->deliveryStore->expireClaims($scope);
        [$limit] = $this->bulkWindow();
        // Every recipient is saved in one transaction before the first request. Up to the commit a failure leaves nothing
        // behind, so the exception can go to Mautic, which counts the message as failed and sends it again later.
        $this->deliveryStore->enqueueBatches((new BulkBatcher())->batches($this->bulkDeliveries($scope, $existing), $limit), $scope);
        $emailId = $this->getEmailIdFromMetadata($this->message->getMetadata());
        try {
            // Read what actually won the inserts, including content another worker saved first.
            $this->sendSavedRows($this->deliveryStore->dueForIds($ids, $scope));
        } catch (\Throwable $e) {
            // From here on the outbox owns every recipient: submitted ones are recorded or expire to unknown, the others
            // stay due for mautic:ses:bulk retry. Throwing would make Mautic send the whole message again under new
            // tracking hashes, which the outbox cannot recognise, so recipients SES accepted would get it twice.
            $this->logger->error('SES bulk sending stopped after the recipients were saved; mautic:ses:bulk retry submits the ones left.', ['email_id' => $emailId, 'exception' => $e]);

            return;
        }
        $this->logger->info('SES transport batch persisted and processed.', ['email_id' => $emailId, 'bulk' => 'auto']);
    }

    /**
     * Requests in a window can leave together. Limit the whole window to at most one second of the configured rate;
     * the sender reserves its full recipient quota before queuing any requests and settles it before the next window.
     *
     * @return array{int, int} recipients per request, requests in flight
     */
    private function bulkWindow(): array
    {
        $rate = max(1, (int) ($this->settings['maxSendRate'] ?? 14));
        $concurrency = min($rate, max(1, (int) ($this->settings['bulkConcurrency'] ?? 2)));

        return [min(50, (int) ($this->settings['bulkBatchSize'] ?? 50), max(1, intdiv($rate, $concurrency))), $concurrency];
    }

    /** Retry commands and first submissions share precisely the same limiter. */
    private function acquireRecipientQuota(int $recipients): void
    {
        $rate = max(1, (int) ($this->settings['maxSendRate'] ?? 14));
        $bucket = $this->pathsHelper->getSystemPath('cache', true).'/ses_token_bucket.json';
        while ($recipients > 0) {
            $count = min($recipients, $rate);
            $this->acquireTokens($bucket, $count, $rate);
            $recipients -= $count;
        }
    }

    /** Used by the bounded cron recovery command; never retries unknown acceptance. */
    public function retryBulk(int $limit = 1000): int
    {
        if ('auto' !== ($this->settings['bulk'] ?? 'off') || !$this->deliveryStore || !$this->bulkSender) {
            throw new \LogicException('Enable bulk=auto to process the SES outbox.');
        }
        $this->deliveryStore->assertInstalled();
        BulkSender::assertSupported($this->client);
        $scope = BulkSender::scope($this->client);
        $this->deliveryStore->expireClaims($scope);
        $due = $this->deliveryStore->due($scope, $limit);
        $this->sendSavedRows($due);

        return count($due);
    }

    /** Queue replays and cron recovery both batch persisted content, never newly rendered replacements. */
    private function sendSavedRows(iterable $rows): void
    {
        $deliveries = (function () use ($rows): \Generator {
            $content = null;
            foreach ($rows as $row) {
                // Shared content is immutable. Raw content can be dropped by another worker, so always reread it.
                if (null === $content || $content['id'] !== $row['content_id'] || 'raw' === $content['operation']) {
                    $content = $this->deliveryStore->content($row['content_id']);
                }
                if (null === $content['payload']) {
                    // Another process made this raw delivery final since due() listed it, so its claim would fail anyway.
                    continue;
                }
                yield ['id' => $row['id'], 'email_id' => $row['email_id'], 'operation' => $content['operation'], 'common' => $content['payload'], 'entry' => json_decode($row['entry'], true, 512, JSON_THROW_ON_ERROR)];
            }
        })();
        [$count, $concurrency] = $this->bulkWindow();
        // One send() call for all batches keeps up to $concurrency requests in flight.
        $batches = (static function (\Generator $batches): \Generator {
            foreach ($batches as $batch) {
                yield array_column($batch, 'id');
            }
        })((new BulkBatcher())->batches($deliveries, $count));
        $this->bulkSender->send($this->client, $batches, fn (int $recipients) => $this->acquireRecipientQuota($recipients), $concurrency);
    }

    /** Judged on the first recipient only, before anything is persisted, charged or submitted. */
    private function bulkEligibilityReason(string $scope): ?string
    {
        $metadata = $this->message->getMetadata();
        $recipient = array_key_first($metadata);
        try {
            $this->bulkEntry($scope, $recipient, $metadata[$recipient]);
        } catch (IneligibleMessage $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** @param array<string, true> $existing */
    private function bulkDeliveries(string $scope, array $existing = []): \Generator
    {
        foreach ($this->message->getMetadata() as $recipient => $data) {
            if (isset($existing[$this->deliveryId($scope, $recipient, $data)])) {
                continue;
            }
            $reason = '';
            try {
                ['id' => $id, 'common' => $common, 'entry' => $entry] = $this->bulkEntry($scope, $recipient, $data);
                $operation = 'bulk';
            } catch (IneligibleMessage $e) {
                $id = $this->deliveryId($scope, $recipient, $data);
                $reason = $e->getMessage();
                $common = $this->rawRecipient($recipient, $data);
                $common['EmailTags'] = $this->deliveryTag($common['EmailTags'] ?? [], $id);
                // Raw MIME can contain arbitrary bytes. Store the wire blob losslessly in JSON.
                $common['Content']['Raw']['Data'] = base64_encode($common['Content']['Raw']['Data']);
                $entry = [];
                $operation = 'raw';
            }
            yield ['id' => $id, 'tracking_hash' => $data['hashId'], 'email_id' => (int) $data['emailId'], 'operation' => $operation, 'common' => $common, 'entry' => $entry, 'reason' => $reason];
        }
    }

    /**
     * @return array{id: string, common: array, entry: array}
     *
     * @throws IneligibleMessage
     */
    private function bulkEntry(string $scope, string $recipient, array $data): array
    {
        $id = $this->deliveryId($scope, $recipient, $data);
        $this->compiler ??= new SharedTemplateCompiler();
        $compiled = $this->compiler->compile($this->message, $data['tokens'] ?? []);
        // Only headers need local replacement. Shared bodies are not rendered/serialized here.
        $headers = clone $this->message;
        $headers->clearMetadata();
        $headers->html(null)->text(null)->subject('');
        $headers->to(new Address($recipient, $data['name'] ?? ''));
        $tokens = $data['tokens'] ?? [];
        ksort($tokens);
        MailHelper::searchReplaceTokens(array_keys($tokens), $tokens, $headers);
        $common = [];
        $this->addSesHeaders($common, $headers, $data);
        // Mautic sets Return-Path from the custom return path or a bounce address. SES uses that header of a raw message
        // only as the address for bounce and complaint notifications, and SendBulkEmail takes that address as a parameter.
        if ($returnPath = $headers->getReturnPath()) {
            if (isset($common['FeedbackForwardingEmailAddress']) && 0 !== strcasecmp($common['FeedbackForwardingEmailAddress'], $returnPath->getEncodedAddress())) {
                throw new IneligibleMessage('return_path_conflict');
            }
            $common['FeedbackForwardingEmailAddress'] = $returnPath->getEncodedAddress();
        }
        $tags = $common['EmailTags'] ?? [];
        unset($common['EmailTags']);
        $tags = $this->deliveryTag($tags, $id);
        $entry = [
            'Destination' => ['ToAddresses' => $this->stringifyAddresses($headers->getTo())],
            'ReplacementEmailContent' => ['ReplacementTemplate' => ['ReplacementTemplateData' => $compiled['data']]],
            'ReplacementHeaders' => $this->bulkHeaders($headers),
            'ReplacementTags' => $tags,
        ];
        $common['DefaultContent'] = ['Template' => ['TemplateContent' => $compiled['template'], 'TemplateData' => '{}']];
        if (BulkBatcher::bytes(BulkBatcher::request($common, [$entry])) > BulkBatcher::MAX_BYTES) {
            throw new IneligibleMessage('request_size');
        }

        return ['id' => $id, 'common' => $common, 'entry' => $entry];
    }

    private function deliveryId(string $scope, string $recipient, array $data): string
    {
        return hash('sha256', $scope.'|'.$data['emailId'].'|'.$data['hashId'].'|'.$recipient);
    }

    private function deliveryTag(array $tags, string $id): array
    {
        $tags = array_values(array_filter($tags, static fn (array $tag): bool => 'mautic_delivery_id' !== ($tag['Name'] ?? '')));
        $tags[] = ['Name' => 'mautic_delivery_id', 'Value' => $id];

        return $tags;
    }

    private function bulkHeaders(MauticMessage $message): array
    {
        $result = [];
        foreach ($message->getHeaders()->all() as $header) {
            $name = $header->getName();
            if ($header instanceof MetadataHeader || in_array(strtolower($name), ['from', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'date', 'message-id', 'mime-version', 'content-type', 'content-transfer-encoding'], true)) {
                continue;
            }
            // Mautic 7 sets Sender to the From address. SES sets the envelope sender itself and RFC 5322 only requires Sender when it differs from From.
            if ('sender' === strtolower($name)) {
                if (0 !== strcasecmp($message->getSender()?->getAddress() ?? '', $message->getFrom()[0]->getAddress())) {
                    throw new IneligibleMessage('sender_differs_from_from');
                }
                continue;
            }
            // Sent as FeedbackForwardingEmailAddress by bulkEntry().
            if ('return-path' === strtolower($name)) {
                continue;
            }
            if (!preg_match('/^(x-|list-)/i', $name) && !in_array(strtolower($name), ['precedence', 'feedback-id', 'auto-submitted'], true)) {
                throw new IneligibleMessage('unsupported_header');
            }
            $value = $header->getBodyAsString();
            // Mautic removes a custom header whose tokens resolve to nothing; SES requires a value.
            if ('' === $value) {
                continue;
            }
            if (strlen($name) > 126 || strlen($value) > 870 || preg_match('/[\r\n]/', $value)) {
                throw new IneligibleMessage('header_size_or_folding');
            }
            // SES accepts printable ASCII only, without space or colon in the name (a tab in a value, for example, is refused).
            if (!preg_match('/^[!-9;-~]+$/D', $name) || !preg_match('/^[ -~]+$/D', $value)) {
                throw new IneligibleMessage('header_value');
            }
            $result[] = ['Name' => $name, 'Value' => $value];
        }
        if (count($result) > 15) {
            throw new IneligibleMessage('header_count');
        }

        return $result;
    }

    private function rawRecipient(string $recipient, array $mailData): array
    {
        $sentMessage = clone $this->message;
        $sentMessage->clearMetadata();
        $sentMessage->updateLeadIdHash($mailData['hashId'] ?? null);
        $sentMessage->to(new Address($recipient, $mailData['name'] ?? ''));
        $tokens = $mailData['tokens'] ?? [];
        ksort($tokens);
        MailHelper::searchReplaceTokens(array_keys($tokens), $tokens, $sentMessage);
        $this->updateEmailFields($sentMessage);
        $payload = [];
        $this->addSesHeaders($payload, $sentMessage, $mailData);
        $payload['Destination'] = ['ToAddresses' => $this->stringifyAddresses($sentMessage->getTo()), 'CcAddresses' => $this->stringifyAddresses($sentMessage->getCc()), 'BccAddresses' => $this->stringifyAddresses($sentMessage->getBcc())];
        $payload['Content'] = ['Raw' => ['Data' => $sentMessage->toString()]];

        return $payload;
    }

    /**
     * Convert MauticMessage to JSON payload that works with RAW sends.
     *
     * @return \Generator<array<string, mixed>>
     */
    public function convertMessageToRawPayload(): \Generator
    {
        $metadata = $this->getMetadata();

        $payload = [];
        if (empty($metadata)) {
            $sentMessage = clone $this->message;
            $this->logger->debug('No metadata found, sending email as raw');
            // Update From Address dynamically
            $this->updateEmailFields($sentMessage);

            $this->addSesHeaders($payload, $sentMessage, []);
            $payload = [
                'Content' => [
                    'Raw' => [
                        'Data' => $sentMessage->toString(),
                    ],
                ],
                'Destination' => [
                    'ToAddresses'  => $this->stringifyAddresses($sentMessage->getTo()),
                    'CcAddresses'  => $this->stringifyAddresses($sentMessage->getCc()),
                    'BccAddresses' => $this->stringifyAddresses($sentMessage->getBcc()),
                ],
            ];
            yield $payload;
            $payload = [];

        } else {

            /**
             * This message is a tokenzied message, SES API does not support tokens in Raw Emails
             * We need to create a new message for each recipient.
             */
            foreach ($metadata as $recipient => $mailData) {
                $sentMessage = clone $this->message;
                $sentMessage->clearMetadata();
                $sentMessage->updateLeadIdHash($mailData['hashId']);
                $sentMessage->to(new Address($recipient, $mailData['name'] ?? ''));

                // Sort tokens to ensure the same order in the email =)
                $sortedTokens = $mailData['tokens'];
                ksort($sortedTokens);
                $mauticTokens = array_keys($sortedTokens);
                MailHelper::searchReplaceTokens($mauticTokens, $sortedTokens, $sentMessage);

                // Update From Address dynamically
                $this->updateEmailFields($sentMessage);
                $this->addSesHeaders($payload, $sentMessage, $mailData);
                $payload['Destination'] = [
                    'ToAddresses'  => $this->stringifyAddresses($sentMessage->getTo()),
                    'CcAddresses'  => $this->stringifyAddresses($sentMessage->getCc()),
                    'BccAddresses' => $this->stringifyAddresses($sentMessage->getBcc()),
                ];
                $payload['Content'] = [
                    'Raw' => [
                        'Data' => $sentMessage->toString(),
                    ],
                ];
                yield $payload;
                $payload = [];
            }
        }
    }

    /**
     * Add SES supported headers to the payload.
     *
     * @param array<string, mixed> $payload
     * @param MauticMessage        $sentMessage the message to be sent
     */
    private function addSesHeaders(&$payload, MauticMessage &$sentMessage, array $mailData): void
    {
        $fromAddress = $sentMessage->getFrom()[0];
        $encodedName = $fromAddress->getEncodedName();
        $payload['FromEmailAddress'] = $encodedName !== ''
            ? "$encodedName <{$fromAddress->getEncodedAddress()}>"
            : $fromAddress->getEncodedAddress();

        $payload['ReplyToAddresses'] = $this->stringifyAddresses($this->setReplyTo($sentMessage));

        foreach ($sentMessage->getHeaders()->all() as $header) {
            if (0 === strcasecmp($header->getName(), MauticEmailId::HEADER_NAME)) {
                MauticEmailId::addToSesPayload($payload, $header->getBodyAsString());

                continue;
            }

            if ($header instanceof MetadataHeader) {
                $payload['EmailTags'][] = ['Name' => $header->getKey(), 'Value' => $header->getValue()];
            } else {
                switch ($header->getName()) {
                    case 'X-SES-FEEDBACK-FORWARDING-EMAIL-ADDRESS':
                        $payload['FeedbackForwardingEmailAddress'] = $header->getBodyAsString();
                        $sentMessage->getHeaders()->remove($header->getName());
                        break;
                    case 'X-SES-FEEDBACK-FORWARDING-EMAIL-ADDRESS-IDENTITYARN':
                        $payload['FeedbackForwardingEmailAddressIdentityArn'] = $header->getBodyAsString();
                        $sentMessage->getHeaders()->remove($header->getName());
                        break;
                    case 'X-SES-FROM-EMAIL-ADDRESS-IDENTITYARN':
                        $payload['FromEmailAddressIdentityArn'] = $header->getBodyAsString();
                        $sentMessage->getHeaders()->remove($header->getName());
                        break;
                    case 'List-Unsubscribe':
                        $sentMessage->getHeaders()->remove($header->getName());
                        if(!empty($mailData) && isset($mailData['tokens']['{unsubscribe_url}'])){
                            $sentMessage->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$mailData['tokens']['{unsubscribe_url}'].'>');
                        }
                        break;
                        /*
                         * https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-sesv2-2019-09-27.html#sendemail
                         * ListManagementOptions is stopped intentionally because Mautic is managing this.
                         */
                    case 'X-SES-CONFIGURATION-SET':
                        $payload['ConfigurationSetName'] = $header->getBodyAsString();
                        $sentMessage->getHeaders()->remove($header->getName());
                        break;
                }
            }
        }
    }

    /**
     * @param array<string|int, mixed> $failures
     */
    private function processFailures(array $failures): void
    {
        if (empty($failures)) {
            return;
        }

        // Log failures but do NOT throw. Throwing causes Symfony Messenger to retry
        // the ENTIRE message with ALL recipients (metadata modifications are lost during
        // re-serialization), resulting in duplicate sends. Failed recipients were already
        // retried inline in doSend() with exponential backoff.
        $this->logger->error(sprintf(
            '%d recipients failed after inline retry: %s',
            count($failures),
            implode(', ', $failures)
        ));
    }

    /**
     * @return array|\string[][]
     */
    public function getMetadata()
    {
        return ($this->message instanceof MauticMessage) ? $this->message->getMetadata() : [];
    }

    protected function getCredentials()
    {
        return $this->client->getCredentials()->wait();
    }

    public function getMaxBatchLimit(): int
    {
        // High batch limit so each Messenger message has many contacts.
        // Actual send rate is controlled by micro-batch pacing in doSend().
        $rate = (int) ($this->settings['maxSendRate'] ?? 14);
        $multiplier = (int) ($this->settings['batchMultiplier'] ?? 10);
        return $rate * $multiplier;
    }

    /**
     * Acquire tokens from a shared file-based token bucket.
     * Lock is held only during file read/write (~30µs), NOT during API calls.
     * Workers sleep outside the lock when tokens are unavailable.
     */
    private function acquireTokens(string $bucketFile, int $tokens, int $rate): void
    {
        $fh = @fopen($bucketFile, 'c+');
        if (false === $fh) {
            $message = sprintf(
                'Unable to open SES rate limit token bucket file "%s". Please verify that the Mautic cache directory is writable by the web server/PHP user.',
                $bucketFile
            );
            $this->logger->error($message);

            throw new TransportException($message);
        }

        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            $message = sprintf('Unable to lock SES rate limit token bucket file "%s".', $bucketFile);
            $this->logger->error($message);

            throw new TransportException($message);
        }

        $data = fread($fh, 256);
        $bucket = $data ? json_decode($data, true) : null;
        $now = microtime(true);

        if (!$bucket || !isset($bucket['tokens'], $bucket['last_time'])) {
            $bucket = ['tokens' => 0.0, 'last_time' => $now];
        }

        // Idle time repays existing reservations but does not accumulate capacity for a burst.
        $elapsed = $now - $bucket['last_time'];
        $bucket['tokens'] = min(0.0, (float) $bucket['tokens'] + $elapsed * $rate);
        $bucket['last_time'] = $now;

        // Reserve capacity while holding the lock so workers sharing this file queue behind the same debt.
        // Wait outside the lock before submitting the reserved recipients.
        $bucket['tokens'] -= $tokens;
        $waitUs = (int) ceil((-$bucket['tokens'] / $rate) * 1_000_000);

        if (!ftruncate($fh, 0)) {
            flock($fh, LOCK_UN);
            fclose($fh);
            $message = sprintf('Unable to truncate SES rate limit token bucket file "%s".', $bucketFile);
            $this->logger->error($message);

            throw new TransportException($message);
        }
        rewind($fh);
        if (false === fwrite($fh, json_encode($bucket))) {
            flock($fh, LOCK_UN);
            fclose($fh);
            $message = sprintf('Unable to write SES rate limit token bucket file "%s".', $bucketFile);
            $this->logger->error($message);

            throw new TransportException($message);
        }
        flock($fh, LOCK_UN);
        fclose($fh);

        if ($waitUs > 0) {
            usleep($waitUs);
        }
    }

    private function getEmailIdFromMetadata(array $metadata): ?int
    {
        foreach ($metadata as $email => $details) {
            if (isset($details['emailId'])) {
                return (int) $details['emailId'];
            }
        }

        return null;
    }

    /**
     * Dynamically sets the From Name and From Email based on email metadata or default settings.
     *
     * @param MauticMessage $email
     * @return void
     * @throws \Exception
     */
    private function updateEmailFields(MauticMessage $email): void
    {
        $emailId = $this->getEmailIdFromMetadata($email->getMetadata());
        if ($emailId !== null) {
            $emailEntity = $this->entityManager->getRepository(MauticEmailEntity::class)->find($emailId);
            if ($emailEntity) {
                // Update From Address and Name
                $email = $this->setFrom($email, $emailEntity);

                // Add Custom Headers, checking for duplicates
                $customHeaders = $emailEntity->getHeaders();
                if (!empty($customHeaders)) {
                    foreach ($customHeaders as $headerName => $headerValue) {
                        // Check if the header already exists before adding it
                        if (!$email->getHeaders()->has($headerName)) {
                            $email->getHeaders()->addTextHeader($headerName, $headerValue);
                        }
                    }
                }
            }
        }
    }

    private function setFrom(MauticMessage $email, \Mautic\EmailBundle\Entity\Email $emailEntity): MauticMessage
    {
        // The envelope sender is the Return-Path whenever Mautic sets one (mailer_return_path or a bounce address), so
        // bulk=auto keeps the From address Mautic resolved. bulk=off keeps the envelope sender of 1.0.41.
        $default = 'auto' === ($this->settings['bulk'] ?? 'off') ? ($email->getFrom()[0] ?? null) : null;
        $default ??= $this->envelope->getSender();
        $entityEmailFrom = $default->getAddress();
        $entityNameFrom = $default->getName();
        if (!empty($emailEntity->getFromAddress())) {
            $entityEmailFrom = $emailEntity->getFromAddress();
        }

        if (!empty($emailEntity->getFromName())) {
            $entityNameFrom = $emailEntity->getFromName();
        }

        $email->from(new Address($entityEmailFrom, $entityNameFrom));

        return $email;

    }

    private function setReplyTo(MauticMessage $sentMessage): array
    {

        $emailId = $this->getEmailIdFromMetadata($this->message->getMetadata());
        if($emailId !== null){
            $emailEntity = $this->entityManager->getRepository(MauticEmailEntity::class)->find($emailId);
            if($emailEntity){
                $entityReplyTo = $emailEntity->getReplyToAddress();
                if (!empty($entityReplyTo)) {
                    $entityReplyTo = explode(',', $entityReplyTo);
                    foreach ($entityReplyTo as $key => $value) {
                        $entityReplyTo[$key] = new Address($value);
                    }
                    return $entityReplyTo;
                }
            }
        }
        return $sentMessage->getReplyTo();
    }

}
