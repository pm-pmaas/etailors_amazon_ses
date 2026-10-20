<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Tests\Unit\EventSubscriber;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\Event\TransportWebhookEvent;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\EmailBundle\MonitoredEmail\Search\ContactFinder;
use Mautic\LeadBundle\Model\DoNotContact as DoNotContactModel;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\AmazonSesBundle\EventSubscriber\CallbackSubscriber;
use MauticPlugin\AmazonSesBundle\Helper\SnsWebhookAuthenticator;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class CallbackSubscriberTest extends TestCase
{
    public const TRUSTED_TOPIC_ARN = 'arn:aws:sns:eu-west-1:123456789012:mautic-ses';

    public const SIGNING_CERT_URL = 'https://sns.eu-west-1.amazonaws.com/SimpleNotificationService-test.pem';

    private static string $certificatePem = '';

    private static string $privateKeyPem = '';

    public function testCanBeConstructedWithLegacyContainerArgumentOrder(): void
    {
        $subscriber = $this->createSubscriber();

        $this->assertInstanceOf(CallbackSubscriber::class, $subscriber);
    }

    public function testCanBeConstructedWithAuthenticatorFirstContainerArgumentOrder(): void
    {
        $subscriber = new CallbackSubscriber(
            $this->createTransportCallbackThatMustNotBeUsed(),
            $this->createCoreParametersHelper([self::TRUSTED_TOPIC_ARN]),
            $this->createHttpClientThatMustNotBeUsed(),
            $this->createContactFinderThatMustNotBeUsed(),
            $this->createDoNotContactModel(),
            $this->createLeadModel(),
            new SnsWebhookAuthenticator(),
            $this->createTranslator(),
            new NullLogger()
        );

        $this->assertInstanceOf(CallbackSubscriber::class, $subscriber);
    }

    public function testRejectsSnsNotificationWithoutSignature(): void
    {
        $subscriber = $this->createSubscriber($this->createContactFinderThatMustNotBeUsed());
        $event      = $this->createWebhookEvent($this->createSnsNotificationPayload());

        $subscriber->processCallbackRequest($event);

        $this->assertSame(Response::HTTP_FORBIDDEN, $event->getResponse()?->getStatusCode());
    }

    public function testRejectsSnsNotificationWithUntrustedSigningCertUrl(): void
    {
        $subscriber = $this->createSubscriber();
        $payload    = $this->createSnsNotificationPayload([
            'SigningCertURL' => 'https://attacker.example.com/SimpleNotificationService-test.pem',
            'Signature'      => 'invalid',
        ]);
        $event      = $this->createWebhookEvent($payload);

        $subscriber->processCallbackRequest($event);

        $this->assertSame(Response::HTTP_FORBIDDEN, $event->getResponse()?->getStatusCode());
    }

    public function testRejectsSnsNotificationFromUnexpectedTopicArn(): void
    {
        $subscriber = $this->createSubscriber(topicArns: [self::TRUSTED_TOPIC_ARN]);
        $payload    = $this->createSignedSnsNotificationPayload([
            'TopicArn' => 'arn:aws:sns:eu-west-1:123456789012:unexpected-topic',
        ]);
        $event      = $this->createWebhookEvent($payload);

        $subscriber->processCallbackRequest($event);

        $this->assertSame(Response::HTTP_FORBIDDEN, $event->getResponse()?->getStatusCode());
    }

    public function testLogsSnsAuthenticationFailuresAtErrorLevelWithReason(): void
    {
        $logger     = $this->createCollectingLogger();
        $subscriber = $this->createSubscriber(topicArns: [self::TRUSTED_TOPIC_ARN], logger: $logger);
        $payload    = [
            'Type'           => 'Notification',
            'SigningCertURL' => self::SIGNING_CERT_URL,
        ];
        $event      = $this->createWebhookEvent($payload);

        $subscriber->processCallbackRequest($event);

        $errorRecords = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => 'error' === $record['level'],
        ));

        $this->assertSame(Response::HTTP_FORBIDDEN, $event->getResponse()?->getStatusCode());
        $this->assertCount(1, $errorRecords);
        $this->assertSame('Rejected unauthenticated Amazon SNS webhook.', $errorRecords[0]['message']);
        $this->assertSame('SNS payload is missing TopicArn.', $errorRecords[0]['context']['reason']);
        $this->assertNull($errorRecords[0]['context']['topic_arn']);
        $this->assertSame('sns.eu-west-1.amazonaws.com', $errorRecords[0]['context']['signing_cert_host']);
    }

    public function testRejectsDirectSesStyleNotification(): void
    {
        $subscriber = $this->createSubscriber($this->createContactFinderThatMustNotBeUsed());
        $event      = $this->createWebhookEvent([
            'notificationType' => 'Bounce',
            'bounce'           => [
                'bounceType'        => 'Permanent',
                'bounceSubType'     => 'General',
                'bouncedRecipients' => [
                    ['emailAddress' => 'recipient@example.com'],
                ],
            ],
            'mail'             => [
                'messageId' => 'direct-message-id',
                'headers'   => [],
            ],
        ]);

        $subscriber->processCallbackRequest($event);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $event->getResponse()?->getStatusCode());
    }

    public function testAcceptsSignedSnsNotificationFromAllowedTopicArn(): void
    {
        $httpClient = $this->createHttpClientReturningCertificate(self::certificatePem());

        $subscriber = $this->createSubscriber(httpClient: $httpClient);
        $event      = $this->createWebhookEvent($this->createSignedSnsNotificationPayload());

        $subscriber->processCallbackRequest($event);

        $this->assertSame(Response::HTTP_OK, $event->getResponse()?->getStatusCode());
    }

    private function createSubscriber(
        ?ContactFinder $contactFinder = null,
        ?HttpClientInterface $httpClient = null,
        array $topicArns = [self::TRUSTED_TOPIC_ARN],
        ?LoggerInterface $logger = null,
    ): CallbackSubscriber {
        $coreParametersHelper = $this->createCoreParametersHelper($topicArns);

        return new CallbackSubscriber(
            $this->createTransportCallbackThatMustNotBeUsed(),
            $coreParametersHelper,
            $httpClient ?? $this->createHttpClientThatMustNotBeUsed(),
            $contactFinder ?? $this->createContactFinderThatMustNotBeUsed(),
            $this->createDoNotContactModel(),
            $this->createLeadModel(),
            $this->createTranslator(),
            $logger ?? new NullLogger()
        );
    }

    private function createCollectingLogger(): AbstractLogger
    {
        return new class() extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level'   => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
    }

    private function createCoreParametersHelper(array $topicArns): CoreParametersHelper
    {
        return new class($topicArns) extends CoreParametersHelper {
            public function __construct(private array $topicArns)
            {
            }

            public function get($name, $default = null)
            {
                return match ($name) {
                    'mailer_dsn'                 => 'mautic+ses+api://ACCESS:SECRET@default?region=eu-west-1',
                    'amazon_ses_sns_topic_arns' => $this->topicArns,
                    default                     => $default,
                };
            }
        };
    }

    private function createTransportCallbackThatMustNotBeUsed(): TransportCallback
    {
        return (new \ReflectionClass(TransportCallback::class))->newInstanceWithoutConstructor();
    }

    private function createContactFinderThatMustNotBeUsed(): ContactFinder
    {
        return (new \ReflectionClass(ContactFinder::class))->newInstanceWithoutConstructor();
    }

    private function createDoNotContactModel(): DoNotContactModel
    {
        return (new \ReflectionClass(DoNotContactModel::class))->newInstanceWithoutConstructor();
    }

    private function createLeadModel(): LeadModel
    {
        return (new \ReflectionClass(LeadModel::class))->newInstanceWithoutConstructor();
    }

    private function createTranslator(): TranslatorInterface
    {
        return new class() implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id;
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };
    }

    private function createHttpClientThatMustNotBeUsed(): HttpClientInterface
    {
        return new class() implements HttpClientInterface {
            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                throw new \RuntimeException('HTTP client must not be used by this test.');
            }

            public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
            {
                throw new \RuntimeException('HTTP client stream must not be used by this test.');
            }

            public function withOptions(array $options): static
            {
                return $this;
            }
        };
    }

    private function createHttpClientReturningCertificate(string $certificate): HttpClientInterface
    {
        return new class($certificate) implements HttpClientInterface {
            public int $requestCount = 0;

            public function __construct(private string $certificate)
            {
            }

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                if ('GET' !== $method || CallbackSubscriberTest::SIGNING_CERT_URL !== $url) {
                    throw new \RuntimeException('Unexpected HTTP request.');
                }

                ++$this->requestCount;

                return new class($this->certificate) implements ResponseInterface {
                    public function __construct(private string $certificate)
                    {
                    }

                    public function getStatusCode(): int
                    {
                        return Response::HTTP_OK;
                    }

                    public function getHeaders(bool $throw = true): array
                    {
                        return [];
                    }

                    public function getContent(bool $throw = true): string
                    {
                        return $this->certificate;
                    }

                    public function toArray(bool $throw = true): array
                    {
                        return [];
                    }

                    public function cancel(): void
                    {
                    }

                    public function getInfo(?string $type = null): mixed
                    {
                        $info = ['http_code' => Response::HTTP_OK];

                        return null === $type ? $info : ($info[$type] ?? null);
                    }
                };
            }

            public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
            {
                throw new \RuntimeException('HTTP client stream must not be used by this test.');
            }

            public function withOptions(array $options): static
            {
                return $this;
            }
        };
    }

    private function createWebhookEvent(array $payload): TransportWebhookEvent
    {
        return new TransportWebhookEvent(new Request(content: json_encode($payload, JSON_THROW_ON_ERROR)));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function createSnsNotificationPayload(array $overrides = []): array
    {
        $payload = [
            'Type'           => 'Notification',
            'MessageId'      => '11111111-2222-3333-4444-555555555555',
            'TopicArn'       => self::TRUSTED_TOPIC_ARN,
            'Message'        => json_encode([
                'notificationType' => 'Delivery',
                'delivery'         => [
                    'recipients' => ['recipient@example.com'],
                ],
                'mail'             => [
                    'messageId' => 'ses-message-id',
                    'headers'   => [],
                ],
            ], JSON_THROW_ON_ERROR),
            'Timestamp'      => '2026-09-16T07:23:00.000Z',
            'SignatureVersion' => '2',
            'SigningCertURL' => self::SIGNING_CERT_URL,
        ];

        return array_replace($payload, $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function createSignedSnsNotificationPayload(array $overrides = []): array
    {
        $payload              = $this->createSnsNotificationPayload($overrides);
        $payload['Signature'] = self::signSnsPayload($payload);

        return $payload;
    }


    /**
     * @param array<string, mixed> $payload
     */
    private static function signSnsPayload(array $payload): string
    {
        openssl_sign(self::snsStringToSign($payload), $signature, self::privateKeyPem(), OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function snsStringToSign(array $payload): string
    {
        return sprintf(
            "Message\n%s\nMessageId\n%s\nTimestamp\n%s\nTopicArn\n%s\nType\n%s\n",
            $payload['Message'],
            $payload['MessageId'],
            $payload['Timestamp'],
            $payload['TopicArn'],
            $payload['Type']
        );
    }

    private static function certificatePem(): string
    {
        self::createCertificatePair();

        return self::$certificatePem;
    }

    private static function privateKeyPem(): string
    {
        self::createCertificatePair();

        return self::$privateKeyPem;
    }

    private static function createCertificatePair(): void
    {
        if ('' !== self::$certificatePem && '' !== self::$privateKeyPem) {
            return;
        }

        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        self::assertNotFalse($privateKey);

        $csr = openssl_csr_new(['commonName' => 'sns.eu-west-1.amazonaws.com'], $privateKey);
        self::assertNotFalse($csr);

        $certificate = openssl_csr_sign($csr, null, $privateKey, 1);
        self::assertNotFalse($certificate);

        openssl_x509_export($certificate, self::$certificatePem);
        openssl_pkey_export($privateKey, self::$privateKeyPem);
    }
}