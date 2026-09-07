<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Tests\Unit\Mailer\Transport;

use Mautic\EmailBundle\Mailer\Message\MauticMessage;
use MauticPlugin\AmazonSesBundle\Mailer\Transport\AmazonSesTransport;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Exception\TransportException;

class AmazonSesTransportTest extends TestCase
{
    public function testAddsMauticEmailIdToSesTags(): void
    {
        $reflection = new \ReflectionClass(AmazonSesTransport::class);
        $transport = $reflection->newInstanceWithoutConstructor();
        $message = (new MauticMessage())
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->text('Test');
        $message->getHeaders()->addTextHeader('X-EMAIL-ID', '42');

        $messageProperty = $reflection->getProperty('message');
        $messageProperty->setValue($transport, $message);

        $payload = [];
        $method = $reflection->getMethod('addSesHeaders');
        $method->invokeArgs($transport, [&$payload, &$message, []]);

        $this->assertContains(
            ['Name' => 'X-EMAIL-ID', 'Value' => '42'],
            $payload['EmailTags']
        );
    }

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
