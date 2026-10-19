<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Tests\Unit\Mailer\Transport;

use Mautic\EmailBundle\Entity\Email as MauticEmail;
use Mautic\EmailBundle\Mailer\Message\MauticMessage;
use MauticPlugin\AmazonSesBundle\Mailer\Transport\AmazonSesTransport;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;

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

    public function testSetFromResolvesContactFieldTokens(): void
    {
        $transport = $this->createTransportWithEnvelope();
        $emailEntity = (new MauticEmail())
            ->setFromAddress('{contactfield=sender}')
            ->setFromName('{contactfield=sender_name}');
        $message = (new MauticMessage())
            ->from(new Address('default@example.com', 'Default Sender'));

        $this->invokeSetFrom($transport, $message, $emailEntity, [
            'tokens' => [
                '{contactfield=sender}' => 'contact-sender@example.com',
                '{contactfield=sender_name}' => 'Contact Sender',
            ],
        ]);

        $from = $message->getFrom()[0];
        $this->assertSame('contact-sender@example.com', $from->getAddress());
        $this->assertSame('Contact Sender', $from->getName());
    }

    public function testSetFromKeepsFixedFromValues(): void
    {
        $transport = $this->createTransportWithEnvelope();
        $emailEntity = (new MauticEmail())
            ->setFromAddress('fixed@example.com')
            ->setFromName('Fixed Sender');
        $message = (new MauticMessage())
            ->from(new Address('default@example.com', 'Default Sender'));

        $this->invokeSetFrom($transport, $message, $emailEntity);

        $from = $message->getFrom()[0];
        $this->assertSame('fixed@example.com', $from->getAddress());
        $this->assertSame('Fixed Sender', $from->getName());
    }

    public function testSetFromFallsBackWhenContactFieldTokenIsMissing(): void
    {
        $transport = $this->createTransportWithEnvelope();
        $emailEntity = (new MauticEmail())
            ->setFromAddress('{contactfield=sender}')
            ->setFromName('{contactfield=sender_name}');
        $message = (new MauticMessage())
            ->from(new Address('default@example.com', 'Default Sender'));

        $this->invokeSetFrom($transport, $message, $emailEntity, ['tokens' => []]);

        $from = $message->getFrom()[0];
        $this->assertSame('default@example.com', $from->getAddress());
        $this->assertSame('Default Sender', $from->getName());
    }

    private function createTransportWithEnvelope(): AmazonSesTransport
    {
        $reflection = new \ReflectionClass(AmazonSesTransport::class);
        $transport = $reflection->newInstanceWithoutConstructor();
        $envelopeProperty = $reflection->getProperty('envelope');
        $envelopeProperty->setAccessible(true);
        $envelopeProperty->setValue($transport, new Envelope(
            new Address('fallback@example.com', 'Fallback Sender'),
            [new Address('recipient@example.com')]
        ));

        return $transport;
    }

    private function invokeSetFrom(AmazonSesTransport $transport, MauticMessage $message, MauticEmail $emailEntity, array $mailData = []): void
    {
        $method = new \ReflectionMethod(AmazonSesTransport::class, 'setFrom');
        $method->setAccessible(true);
        $method->invoke($transport, $message, $emailEntity, $mailData);
    }
}
