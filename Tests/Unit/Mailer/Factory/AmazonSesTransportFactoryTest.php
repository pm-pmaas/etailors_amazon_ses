<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Tests\Unit\Mailer\Factory;

use MauticPlugin\AmazonSesBundle\Mailer\Factory\AmazonSesTransportFactory;
use PHPUnit\Framework\TestCase;

class AmazonSesTransportFactoryTest extends TestCase
{
    /**
     * @dataProvider providePasswords
     */
    public function testSanitizePassword(string $input, string $expected): void
    {
        $ref = new \ReflectionMethod(AmazonSesTransportFactory::class, 'sanitizePassword');
        $ref->setAccessible(true);

        $result = $ref->invoke(null, $input);

        $this->assertSame($expected, $result);
    }

    public function providePasswords(): array
    {
        return [
            'html tags removed' => ['<b>password</b>', 'password'],
            'non ascii removed' => ['<i>pässwörd&nbsp;</i>', 'psswrd'],
        ];
    }
}
