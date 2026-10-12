<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Tests\Unit\Helper;

use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use MauticPlugin\AmazonSesBundle\Helper\SnsWebhookAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class SnsWebhookAuthenticatorTest extends TestCase
{
    private const TOPIC = 'arn:aws:sns:eu-west-1:123456789012:mautic-feedback';
    public const SIGNING_CERT_URL = 'https://sns.eu-west-1.amazonaws.com/test.pem';

    public function testAuthenticatesOnlyUntamperedMessagesFromAllowedTopic(): void
    {
        $key       = openssl_pkey_new(['private_key_bits' => 2048]);
        $publicKey = openssl_pkey_get_details($key)['key'];
        $validator = new MessageValidator(static fn (string $url): string => $publicKey);
        $payload   = $this->signedPayload($key, $validator);
        $subject   = new SnsWebhookAuthenticator();

        self::assertTrue($subject->authenticate($payload, [self::TOPIC], $validator));
        self::assertNull($subject->getLastFailureReason());

        $tampered            = $payload;
        $tampered['Message'] = '{"notificationType":"Complaint"}';
        self::assertFalse($subject->authenticate($tampered, [self::TOPIC], $validator));
        self::assertStringStartsWith('SNS signature validation failed:', (string) $subject->getLastFailureReason());

        self::assertFalse($subject->authenticate($payload, [self::TOPIC.'-other'], $validator));
        self::assertSame('SNS TopicArn is not allowed.', $subject->getLastFailureReason());

        self::assertFalse($subject->authenticate(['eventType' => 'Bounce'], [self::TOPIC], $validator));
        self::assertSame('SNS payload is missing TopicArn.', $subject->getLastFailureReason());
    }

    public function testAuthenticatesSignedMessagesWithoutAwsValidatorDependencyPath(): void
    {
        $key       = openssl_pkey_new(['private_key_bits' => 2048]);
        $publicKey = openssl_pkey_get_details($key)['key'];
        $validator = new MessageValidator(static fn (string $url): string => $publicKey);
        $payload   = $this->signedPayload($key, $validator);
        $subject   = new SnsWebhookAuthenticator($this->createHttpClientReturningCertificate($publicKey));

        self::assertTrue($subject->authenticate($payload, [self::TOPIC]));
        self::assertNull($subject->getLastFailureReason());

        $tampered            = $payload;
        $tampered['Message'] = '{"notificationType":"Complaint"}';
        self::assertFalse($subject->authenticate($tampered, [self::TOPIC]));
        self::assertSame('SNS signature validation failed: The message signature is invalid.', $subject->getLastFailureReason());
    }

    public function testRejectsInvalidNativeSignatureValidationInputs(): void
    {
        $key       = openssl_pkey_new(['private_key_bits' => 2048]);
        $publicKey = openssl_pkey_get_details($key)['key'];
        $validator = new MessageValidator(static fn (string $url): string => $publicKey);
        $payload   = $this->signedPayload($key, $validator);
        $subject   = new SnsWebhookAuthenticator($this->createHttpClientReturningCertificate($publicKey));

        $untrustedCertUrl                   = $payload;
        $untrustedCertUrl['SigningCertURL'] = 'https://example.com/test.pem';
        self::assertFalse($subject->authenticate($untrustedCertUrl, [self::TOPIC]));
        self::assertSame('SNS signature validation failed: The certificate is located on an invalid domain.', $subject->getLastFailureReason());

        $unsupportedSignatureVersion                      = $payload;
        $unsupportedSignatureVersion['SignatureVersion']   = '3';
        self::assertFalse($subject->authenticate($unsupportedSignatureVersion, [self::TOPIC]));
        self::assertSame('SNS signature validation failed: The SignatureVersion "3" is not supported.', $subject->getLastFailureReason());

        $missingSignature = $payload;
        unset($missingSignature['Signature']);
        self::assertFalse($subject->authenticate($missingSignature, [self::TOPIC]));
        self::assertSame('SNS signature validation failed: SNS payload is missing Signature.', $subject->getLastFailureReason());
    }

    /** @return array<string, string> */
    private function signedPayload(\OpenSSLAsymmetricKey $key, MessageValidator $validator): array
    {
        $payload = [
            'Type'              => 'Notification',
            'Message'           => '{"notificationType":"Bounce"}',
            'MessageId'         => 'unit-test',
            'Timestamp'         => '2026-09-20T00:00:00Z',
            'TopicArn'          => self::TOPIC,
            'SignatureVersion'  => '2',
            'Signature'         => '',
            'SigningCertURL'    => self::SIGNING_CERT_URL,
        ];
        openssl_sign(
            $validator->getStringToSign(new Message($payload)),
            $signature,
            $key,
            OPENSSL_ALGO_SHA256,
        );
        $payload['Signature'] = base64_encode($signature);

        return $payload;
    }

    private function createHttpClientReturningCertificate(string $certificate): HttpClientInterface
    {
        return new class($certificate) implements HttpClientInterface {
            public function __construct(private string $certificate)
            {
            }

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                if ('GET' !== $method || SnsWebhookAuthenticatorTest::SIGNING_CERT_URL !== $url) {
                    throw new \RuntimeException('Unexpected HTTP request.');
                }

                return new class($this->certificate) implements ResponseInterface {
                    public function __construct(private string $certificate)
                    {
                    }

                    public function getStatusCode(): int
                    {
                        return 200;
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
                        $info = ['http_code' => 200];

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
}
