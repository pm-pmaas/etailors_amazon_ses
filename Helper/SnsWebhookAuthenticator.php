<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Helper;

use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SnsWebhookAuthenticator
{
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

        if (!class_exists(Message::class) || !class_exists(MessageValidator::class)) {
            return $this->fail('SNS message validator dependency is not installed.');
        }

        try {
            $message = new Message($payload);
            ($validator ?? $this->createMessageValidator())->validate($message);

            return true;
        } catch (\Throwable $e) {
            return $this->fail(sprintf(
                'SNS signature validation failed: %s',
                '' !== $e->getMessage() ? $e->getMessage() : $e::class,
            ));
        }
    }

    private function createMessageValidator(): MessageValidator
    {
        if (null === $this->client) {
            return new MessageValidator();
        }

        return new MessageValidator(function (string $certificateUrl): string {
            $response = $this->client->request('GET', $certificateUrl, ['timeout' => 5]);
            $statusCode = $response->getStatusCode();

            if (200 !== $statusCode) {
                throw new \RuntimeException(sprintf('Unable to retrieve SNS signing certificate, HTTP %d.', $statusCode));
            }

            return $response->getContent(false);
        });
    }

    private function fail(string $reason): bool
    {
        $this->lastFailureReason = $reason;

        return false;
    }
}
