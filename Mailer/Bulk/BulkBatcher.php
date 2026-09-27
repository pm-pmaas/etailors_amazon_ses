<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Mailer\Bulk;

/** Only one bounded batch is retained; incompatible request fields flush it. */
final class BulkBatcher
{
    public const MAX_BYTES = 1000000; // Conservative headroom below the documented inline limit.

    public static function request(array $common, array $entries): array
    {
        $common['BulkEmailEntries'] = array_values($entries);

        return $common;
    }

    public static function bytes(array $request): int
    {
        // Default JSON escaping is conservative compared with the SDK's serializer.
        return strlen(json_encode($request, JSON_THROW_ON_ERROR));
    }

    /** @param iterable<array<string, mixed>> $deliveries */
    public function batches(iterable $deliveries, int $limit): \Generator
    {
        $limit = max(1, min(50, $limit));
        $batch = [];
        $fingerprint = null;
        foreach ($deliveries as $delivery) {
            $key = hash('sha256', serialize([$delivery['operation'], $delivery['common'], $delivery['email_id']]));
            $entries = array_column($batch, 'entry');
            $entries[] = $delivery['entry'];
            if ($batch && ($key !== $fingerprint || count($batch) >= $limit || 'raw' === $delivery['operation'] || self::bytes(self::request($delivery['common'], $entries)) > self::MAX_BYTES)) {
                yield $batch;
                $batch = [];
            }
            $batch[] = $delivery;
            $fingerprint = $key;
            if ('raw' === $delivery['operation']) {
                yield $batch;
                $batch = [];
            }
        }
        if ($batch) {
            yield $batch;
        }
    }
}
