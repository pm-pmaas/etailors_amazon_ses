<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Tests\Unit\Mailer\Factory;

use MauticPlugin\AmazonSesBundle\Mailer\Factory\AmazonSesTransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Transport\Dsn;

class AmazonSesTransportFactoryBulkOptionsTest extends TestCase
{
    /**
     * @dataProvider validOptions
     */
    public function testBulkOptionsAreParsed(string $query, array $expected): void
    {
        self::assertSame($expected, AmazonSesTransportFactory::bulkOptions(self::dsn($query)));
    }

    public static function validOptions(): array
    {
        return [
            'defaults' => ['', ['bulk' => 'off', 'bulkBatchSize' => 50, 'bulkConcurrency' => 2]],
            'explicit' => ['bulk=auto&bulk_batch_size=10&bulk_concurrency=4', ['bulk' => 'auto', 'bulkBatchSize' => 10, 'bulkConcurrency' => 4]],
        ];
    }

    /**
     * @dataProvider invalidOptions
     */
    public function testInvalidBulkOptionsAreRejected(string $query, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        AmazonSesTransportFactory::bulkOptions(self::dsn($query));
    }

    public static function invalidOptions(): array
    {
        return [
            'unknown bulk mode'    => ['bulk=yes', 'SES bulk must be off or auto.'],
            'batch size zero'      => ['bulk_batch_size=0', 'SES bulk_batch_size must be an integer between 1 and 50.'],
            'batch size above 50'  => ['bulk_batch_size=51', 'SES bulk_batch_size must be an integer between 1 and 50.'],
            'concurrency zero'     => ['bulk_concurrency=0', 'SES bulk_concurrency must be an integer between 1 and 10.'],
            'concurrency above 10' => ['bulk_concurrency=11', 'SES bulk_concurrency must be an integer between 1 and 10.'],
        ];
    }

    private static function dsn(string $query): Dsn
    {
        return Dsn::fromString('mautic+ses+api://k:s@default?region=eu-central-1'.('' === $query ? '' : '&'.$query));
    }
}
