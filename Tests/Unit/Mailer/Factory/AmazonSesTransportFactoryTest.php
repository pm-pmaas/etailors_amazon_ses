<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Tests\Unit\Mailer\Factory;

use MauticPlugin\AmazonSesBundle\Mailer\Factory\AmazonSesTransportFactory;
use PHPUnit\Framework\TestCase;

class AmazonSesTransportFactoryTest extends TestCase
{
    public function testTransportCreationCanBeExtended(): void
    {
        $method = new \ReflectionMethod(AmazonSesTransportFactory::class, 'createTransport');

        $this->assertTrue($method->isProtected());
    }

    /**
     * @dataProvider providePasswords
     */
    public function testSanitizePassword(string $input, string $expected): void
    {
        $reflection = new \ReflectionClass(AmazonSesTransportFactory::class);
        $factory = $reflection->newInstanceWithoutConstructor();
        $ref = new \ReflectionMethod(AmazonSesTransportFactory::class, 'sanitizePassword');
        $ref->setAccessible(true);

        $result = $ref->invoke($factory, $input);

        $this->assertSame($expected, $result);
    }

    public static function providePasswords(): array
    {
        return [
            'html tags removed' => ['<b>password</b>', 'password'],
            'non ascii removed' => ['<i>pässwörd&nbsp;</i>', 'psswrd&nbsp;'],
        ];
    }
}
