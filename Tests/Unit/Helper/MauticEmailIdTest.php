<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Tests\Unit\Helper;

use MauticPlugin\AmazonSesBundle\Helper\MauticEmailId;
use PHPUnit\Framework\TestCase;

class MauticEmailIdTest extends TestCase
{
    public function testAddsEmailIdAsSesTag(): void
    {
        $payload = [];

        MauticEmailId::addToSesPayload($payload, '42');

        $this->assertSame([
            ['Name' => 'X-EMAIL-ID', 'Value' => '42'],
        ], $payload['EmailTags']);
    }

    public function testResolvesEmailIdFromOriginalHeader(): void
    {
        $payload = [
            'mail' => [
                'headers' => [
                    ['name' => 'x-email-id', 'value' => '123'],
                ],
            ],
        ];

        $this->assertSame(123, MauticEmailId::fromSesNotification($payload));
    }

    public function testResolvesEmailIdFromEventPublishingTag(): void
    {
        $payload = [
            'mail' => [
                'tags' => [
                    'X-EMAIL-ID' => ['456'],
                ],
            ],
        ];

        $this->assertSame(456, MauticEmailId::fromSesNotification($payload));
    }

    public function testFallsBackToTagWhenHeaderIsInvalid(): void
    {
        $payload = [
            'mail' => [
                'headers' => [
                    ['name' => 'X-EMAIL-ID', 'value' => 'invalid'],
                ],
                'tags' => [
                    'x-email-id' => ['789'],
                ],
            ],
        ];

        $this->assertSame(789, MauticEmailId::fromSesNotification($payload));
    }

    /**
     * @dataProvider provideInvalidPayloads
     */
    public function testRejectsMissingOrInvalidEmailId(array $payload): void
    {
        $this->assertNull(MauticEmailId::fromSesNotification($payload));
    }

    public function provideInvalidPayloads(): array
    {
        return [
            'missing mail object' => [[]],
            'missing headers and tags' => [['mail' => []]],
            'zero' => [['mail' => ['tags' => ['X-EMAIL-ID' => ['0']]]]],
            'negative' => [['mail' => ['headers' => [['name' => 'X-EMAIL-ID', 'value' => '-1']]]]],
            'non numeric' => [['mail' => ['headers' => [['name' => 'X-EMAIL-ID', 'value' => 'abc']]]]],
        ];
    }
}
