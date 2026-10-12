<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Helper;

use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SnsWebhookAuthenticator
{
    private const SIGNATURE_VERSION_1 = '1';
    private const SIGNATURE_VERSION_2 = '2';

    /** @var list<string> */
    private const SIGNABLE_KEYS = [
        'Message',
        'MessageId',
        'Subject',
        'SubscribeURL',
        'Timestamp',
        'Token',
        'TopicArn',
        'Type',
    ];

    private ?string $lastFailureReason = null;

    public function __construct(private ?HttpClientInterface $client = null)
    {
    }

    public function getLastFailureReason(): ?string
    {
        return $this->lastFailureReason;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $allowedTopicArns
     */
    public function authenticate(
        array $payload,
        array $allowedTopicArns,
        ?MessageValidator $validator = null,
    ): bool {
        $this->lastFailureReason = null;

        $topicArn = (string) ($payload['TopicArn'] ?? '');
        $allowed  = array_filter(
            $allowedTopicArns,
            static fn (string $candidate): bool => hash_equals($candidate, $topicArn),
        );

        if ('' === $topicArn) {
            return $this->fail('SNS payload is missing TopicArn.');
        }

        if ([] === $allowed) {
            return $this->fail('SNS TopicArn is not allowed.');
        }

        if (!in_array($payload['Type'] ?? null, ['Notification', 'SubscriptionConfirmation'], true)) {
            return $this->fail('SNS payload has an unsupported Type.');
        }

        try {
            if (null !== $validator) {
                if (!class_exists(Message::class)) {
                    return $this->fail('SNS message validator dependency is not installed.');
                }

                $validator->validate(new Message($payload));

                return true;
            }

            $this->validateSignature($payload);

            return true;
        } catch (\Throwable $e) {
            return $this->fail(sprintf(
                'SNS signature validation failed: %s',
                '' !== $e->getMessage() ? $e->getMessage() : $e::class,
            ));
        }
    }

    /** @param array<string, mixed> $payload */
    private function validateSignature(array $payload): void
    {
        $certificateUrl = $this->getRequiredPayloadValue($payload, 'SigningCertURL');
        $this->validateCertificateUrl($certificateUrl);

        $signatureVersion = $this->getRequiredPayloadValue($payload, 'SignatureVersion');
        if (!in_array($signatureVersion, [self::SIGNATURE_VERSION_1, self::SIGNATURE_VERSION_2], true)) {
            throw new \RuntimeException(sprintf('The SignatureVersion "%s" is not supported.', $signatureVersion));
        }

        $signature = base64_decode($this->getRequiredPayloadValue($payload, 'Signature'), true);
        if (false === $signature) {
            throw new \RuntimeException('The message signature is not valid base64.');
        }

        $certificate = $this->fetchCertificate($certificateUrl);
        $publicKey   = openssl_get_publickey($certificate);

        if (false === $publicKey) {
            throw new \RuntimeException('Cannot get the public key from the certificate.');
        }

        $algorithm = self::SIGNATURE_VERSION_1 === $signatureVersion ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256;

        if (1 !== openssl_verify($this->getStringToSign($payload), $signature, $publicKey, $algorithm)) {
            throw new \RuntimeException('The message signature is invalid.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function getRequiredPayloadValue(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (!is_string($value) || '' === $value) {
            throw new \RuntimeException(sprintf('SNS payload is missing %s.', $key));
        }

        return $value;
    }

    private function validateCertificateUrl(string $certificateUrl): void
    {
        $parsed = parse_url($certificateUrl);

        if (
            !is_array($parsed)
            || 'https' !== ($parsed['scheme'] ?? null)
            || !isset($parsed['host'])
            || !is_string($parsed['host'])
            || !str_ends_with($certificateUrl, '.pem')
            || 1 !== preg_match('/^sns\.[a-zA-Z0-9\-]{3,}\.amazonaws\.com(\.cn)?$/', $parsed['host'])
        ) {
            throw new \RuntimeException('The certificate is located on an invalid domain.');
        }
    }

    private function fetchCertificate(string $certificateUrl): string
    {
        if (null === $this->client) {
            $certificate = @file_get_contents($certificateUrl);

            if (false === $certificate) {
                throw new \RuntimeException(sprintf('Unable to retrieve SNS signing certificate from "%s".', $certificateUrl));
            }

            return $certificate;
        }

        $response = $this->client->request('GET', $certificateUrl, ['timeout' => 5]);
        $statusCode = $response->getStatusCode();

        if (200 !== $statusCode) {
            throw new \RuntimeException(sprintf('Unable to retrieve SNS signing certificate, HTTP %d.', $statusCode));
        }

        return $response->getContent(false);
    }

    /** @param array<string, mixed> $payload */
    private function getStringToSign(array $payload): string
    {
        $stringToSign = '';

        foreach (self::SIGNABLE_KEYS as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) {
                $stringToSign .= sprintf("%s\n%s\n", $key, (string) $payload[$key]);
            }
        }

        return $stringToSign;
    }

    private function fail(string $reason): bool
    {
        $this->lastFailureReason = $reason;

        return false;
    }
}
