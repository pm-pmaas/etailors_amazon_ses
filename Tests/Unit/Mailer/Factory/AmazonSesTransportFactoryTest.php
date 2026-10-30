<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Tests\Unit\Mailer\Factory;

use MauticPlugin\AmazonSesBundle\Mailer\Factory\AmazonSesTransportFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\Dsn;

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
    #[DataProvider('providePasswords')]
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

    public function testAmazonClientConfiguresDefaultHttpTimeouts(): void
    {
        $config = $this->getAmazonClientConfig([
            'region' => 'eu-west-1',
        ]);

        $this->assertSame(5.0, $config['http']['connect_timeout'] ?? null);
        $this->assertSame(60.0, $config['http']['timeout'] ?? null);
    }

    public function testAmazonClientConfiguresCustomHttpTimeouts(): void
    {
        $config = $this->getAmazonClientConfig([
            'region' => 'eu-west-1',
            'connect_timeout' => '2.5',
            'timeout' => '45',
        ]);

        $this->assertSame(2.5, $config['http']['connect_timeout'] ?? null);
        $this->assertSame(45.0, $config['http']['timeout'] ?? null);
    }

    private function getAmazonClientConfig(array $options): array
    {
        $factory = (new \ReflectionClass(AmazonSesTransportFactory::class))->newInstanceWithoutConstructor();
        $dsn = new Dsn(
            'mautic+ses+api',
            'default',
            'access-key',
            'secret-key',
            null,
            $options
        );

        $this->assertTrue(method_exists($factory, 'getAmazonClientConfig'));

        $method = new \ReflectionMethod(AmazonSesTransportFactory::class, 'getAmazonClientConfig');
        $method->setAccessible(true);

        return $method->invoke($factory, $dsn, 'access-key', 'secret-key', $options['region']);
    }
}
