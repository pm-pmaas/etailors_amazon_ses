<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Tests\Unit\Mailer\Bulk;

use MauticPlugin\AmazonSesBundle\Mailer\Bulk\BulkBatcher;
use PHPUnit\Framework\TestCase;

class BulkBatcherTest extends TestCase
{
    public function testGroupsByRequestAndRecipientLimit(): void
    {
        $rows = [];
        for ($i = 0; $i < 101; ++$i) {
            $rows[] = ['operation' => 'bulk', 'common' => ['FromEmailAddress' => 'a@example.com'], 'entry' => ['recipient' => $i], 'email_id' => 1];
        }
        $rows[] = ['operation' => 'bulk', 'common' => ['FromEmailAddress' => 'b@example.com'], 'entry' => [], 'email_id' => 1];
        self::assertSame([50, 50, 1, 1], array_map('count', iterator_to_array((new BulkBatcher())->batches($rows, 100))));
        self::assertSame([1, 1], array_map('count', iterator_to_array((new BulkBatcher())->batches(array_slice($rows, 0, 2), 1))));
    }

    public function testSplitsByEscapedJsonBytes(): void
    {
        $row = ['operation' => 'bulk', 'common' => ['template' => str_repeat('a', 200000)], 'entry' => ['value' => str_repeat('"', 200000)], 'email_id' => 1];
        $batches = iterator_to_array((new BulkBatcher())->batches([$row, $row], 50));
        self::assertCount(2, $batches);
        foreach ($batches as $batch) {
            self::assertLessThanOrEqual(BulkBatcher::MAX_BYTES, BulkBatcher::bytes(BulkBatcher::request($row['common'], array_column($batch, 'entry'))));
        }
    }
}
