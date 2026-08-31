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

    public function __construct(
        SesV2Client $amazonclient,
        EntityManagerInterface $entityManager,
        PathsHelper $pathsHelper,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
        $settings = [],
    ) {
        parent::__construct($dispatcher, $logger);
        $this->logger     = $logger;
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

            // Use centralized method for updating From address
            $this->updateEmailFields($email);

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
        while (true) {
            $fh = fopen($bucketFile, 'c+');
            flock($fh, LOCK_EX);

            $data = fread($fh, 256);
            $bucket = $data ? json_decode($data, true) : null;
            $now = microtime(true);

            if (!$bucket || !isset($bucket['tokens'], $bucket['last_time'])) {
                $bucket = ['tokens' => 0.0, 'last_time' => $now];
            }

            // Refill tokens based on elapsed time, cap at rate
            $elapsed = $now - $bucket['last_time'];
            $bucket['tokens'] = min((float) $rate, $bucket['tokens'] + $elapsed * $rate);
            $bucket['last_time'] = $now;

            if ($bucket['tokens'] >= $tokens) {
                $bucket['tokens'] -= $tokens;
                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, json_encode($bucket));
                flock($fh, LOCK_UN);
                fclose($fh);
                return;
            }

            // Not enough tokens — save current state so next iteration sees elapsed time,
            // then release lock and sleep outside
            $deficit = $tokens - $bucket['tokens'];
            $waitUs = (int) ceil(($deficit / $rate) * 1_000_000);

            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($bucket));
            flock($fh, LOCK_UN);
            fclose($fh);

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
        $entityEmailFrom = $this->envelope->getSender()->getAddress();
        $entityNameFrom = $this->envelope->getSender()->getName();
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
