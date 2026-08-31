<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Tests\Unit\Mailer\Transport;

use MauticPlugin\AmazonSesBundle\Mailer\Transport\AmazonSesTransport;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Exception\TransportException;

class AmazonSesTransportTest extends TestCase
{
    public function testAcquireTokensThrowsTransportExceptionWhenBucketFileCannotBeOpened(): void
    {
        $reflection = new \ReflectionClass(AmazonSesTransport::class);
        $transport = $reflection->newInstanceWithoutConstructor();
        $loggerProperty = $reflection->getProperty('logger');
        $loggerProperty->setAccessible(true);
        $loggerProperty->setValue($transport, new NullLogger());

        $method = new \ReflectionMethod(AmazonSesTransport::class, 'acquireTokens');
        $method->setAccessible(true);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Unable to open SES rate limit token bucket file');

        $method->invoke($transport, __DIR__, 1, 1);
    }
}