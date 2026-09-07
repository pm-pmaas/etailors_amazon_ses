<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Helper;

final class MauticEmailId
{
    public const HEADER_NAME = 'X-EMAIL-ID';

    /**
     * Add the Mautic email ID as an SES message tag.
     *
     * SES event-publishing notifications always expose message tags, while
     * classic identity notifications expose custom headers only when original
     * headers are enabled for that notification type.
     *
     * @param array<string, mixed> $payload
     */
    public static function addToSesPayload(array &$payload, mixed $value): void
    {
        $emailId = self::normalize($value);
        if (null === $emailId) {
            return;
        }

        foreach ($payload['EmailTags'] ?? [] as $index => $tag) {
            if (is_array($tag) && 0 === strcasecmp((string) ($tag['Name'] ?? ''), self::HEADER_NAME)) {
                $payload['EmailTags'][$index]['Value'] = (string) $emailId;

                return;
            }
        }

        $payload['EmailTags'][] = [
            'Name'  => self::HEADER_NAME,
            'Value' => (string) $emailId,
        ];
    }

    /**
     * Resolve the Mautic email ID from an SES notification.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromSesNotification(array $payload): ?int
    {
        $mail = $payload['mail'] ?? null;
        if (!is_array($mail)) {
            return null;
        }

        foreach ($mail['headers'] ?? [] as $header) {
            if (!is_array($header) || 0 !== strcasecmp((string) ($header['name'] ?? ''), self::HEADER_NAME)) {
                continue;
            }

            $emailId = self::normalize($header['value'] ?? null);
            if (null !== $emailId) {
                return $emailId;
            }
        }

        foreach ($mail['tags'] ?? [] as $name => $values) {
            if (0 !== strcasecmp((string) $name, self::HEADER_NAME)) {
                continue;
            }

            foreach (is_array($values) ? $values : [$values] as $value) {
                $emailId = self::normalize($value);
                if (null !== $emailId) {
                    return $emailId;
                }
            }
        }

        return null;
    }

    private static function normalize(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (!is_string($value) || '' === $value || !ctype_digit($value)) {
            return null;
        }

        $emailId = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return false === $emailId ? null : $emailId;
    }
}
