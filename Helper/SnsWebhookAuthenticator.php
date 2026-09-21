<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Helper;

use Aws\Sns\Message;
use Aws\Sns\MessageValidator;

final class SnsWebhookAuthenticator
{
    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $allowedTopicArns
     */
    public function authenticate(
        array $payload,
        array $allowedTopicArns,
        ?MessageValidator $validator = null,
    ): bool {
        $topicArn = (string) ($payload['TopicArn'] ?? '');
        $allowed  = array_filter(
            $allowedTopicArns,
            static fn (string $candidate): bool => hash_equals($candidate, $topicArn),
        );

        if ('' === $topicArn || [] === $allowed) {
            return false;
        }

        if (!in_array($payload['Type'] ?? null, ['Notification', 'SubscriptionConfirmation'], true)) {
            return false;
        }

        try {
            $message = new Message($payload);
            ($validator ?? new MessageValidator())->validate($message);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
