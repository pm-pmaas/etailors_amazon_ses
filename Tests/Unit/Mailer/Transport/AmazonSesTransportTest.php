<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Tests\Unit\Mailer\Transport;

use Mautic\EmailBundle\Entity\Email as MauticEmail;
use Mautic\EmailBundle\Mailer\Message\MauticMessage;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\AmazonSesBundle\Mailer\Transport\AmazonSesTransport;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;

class AmazonSesTransportTest extends TestCase
{
    public function testCurrentMessageAndEnvelopeAreAvailableToSubclasses(): void
    {
        $reflection = new \ReflectionClass(AmazonSesTransport::class);

        $this->assertTrue($reflection->getMethod('getMessage')->isProtected());
        $this->assertTrue($reflection->getMethod('getEnvelope')->isProtected());
    }

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
        $this->assertSame('contact-sender@example.com', $message->getSender()?->getAddress());
        $this->assertSame('Contact Sender', $message->getSender()?->getName());
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
        $this->assertNull($message->getSender());
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

    public function testSetFromFallsBackToEnvelopeWhenMessageHasNoValidFrom(): void
    {
        $transport = $this->createTransportWithEnvelope();
        $emailEntity = (new MauticEmail())
            ->setFromAddress('{contactfield=sender}')
            ->setFromName('{contactfield=sender_name}');
        $message = new class extends MauticMessage {
            private bool $throwOnGetFrom = true;

            public function getFrom(): array
            {
                if ($this->throwOnGetFrom) {
                    $this->throwOnGetFrom = false;

                    throw new \RuntimeException('Invalid From header');
                }

                return parent::getFrom();
            }
        };

        $this->invokeSetFrom($transport, $message, $emailEntity, ['tokens' => []]);

        $from = $message->getFrom()[0];
        $this->assertSame('fallback@example.com', $from->getAddress());
        $this->assertSame('Fallback Sender', $from->getName());
    }

    public function testUpdateEmailFieldsResolvesContactFieldTokensFromMailData(): void
    {
        $emailEntity = (new MauticEmail())
            ->setFromAddress('{contactfield=sender}')
            ->setFromName('{contactfield=sender_name}');
        $transport = $this->createTransportWithEnvelopeAndEmailEntity($emailEntity);
        $message = (new MauticMessage())
            ->from(new Address('default@example.com', 'Default Sender'));

        $this->invokeUpdateEmailFields($transport, $message, [
            'emailId' => 123,
            'tokens' => [
                '{contactfield=sender}' => 'contact-sender@example.com',
                '{contactfield=sender_name}' => 'Contact Sender',
            ],
        ]);

        $from = $message->getFrom()[0];
        $this->assertSame('contact-sender@example.com', $from->getAddress());
        $this->assertSame('Contact Sender', $from->getName());
    }

    public function testUpdateEmailFieldsResolvesContactFieldTokensFromContactMetadata(): void
    {
        $emailEntity = (new MauticEmail())
            ->setFromAddress('{contactfield=sender}')
            ->setFromName('{contactfield=sender_name}');
        $transport = $this->createTransportWithEnvelopeAndEmailEntity($emailEntity);
        $message = (new MauticMessage())
            ->from(new Address('default@example.com', 'Default Sender'));

        $this->invokeUpdateEmailFields($transport, $message, [
            'emailId' => 123,
            'tokens' => [],
            'lead' => [
                'sender' => 'metadata-sender@example.com',
                'sender_name' => 'Metadata Sender',
            ],
        ]);

        $from = $message->getFrom()[0];
        $this->assertSame('metadata-sender@example.com', $from->getAddress());
        $this->assertSame('Metadata Sender', $from->getName());
    }

    public function testUpdateEmailFieldsResolvesContactFieldTokensFromLeadId(): void
    {
        $emailEntity = (new MauticEmail())
            ->setFromAddress('{contactfield=sender}')
            ->setFromName('{contactfield=sender_name}');
        $lead = new Lead();
        $lead->setFields([
            'core' => [
                'sender' => [
                    'type'  => 'email',
                    'value' => 'lead-sender@example.com',
                ],
                'sender_name' => [
                    'type'  => 'text',
                    'value' => 'Lead Sender',
                ],
            ],
        ]);
        $transport = $this->createTransportWithEnvelopeAndEmailEntity($emailEntity, $lead);
        $message = (new MauticMessage())
            ->from(new Address('default@example.com', 'Default Sender'));

        $this->invokeUpdateEmailFields($transport, $message, [
            'emailId' => 123,
            'leadId'  => 456,
            'tokens'  => [],
        ]);

        $from = $message->getFrom()[0];
        $this->assertSame('lead-sender@example.com', $from->getAddress());
        $this->assertSame('Lead Sender', $from->getName());
    }

    public function testUpdateEmailFieldsResolvesContactFieldTokensFromLeadDatabaseFields(): void
    {
        $emailEntity = (new MauticEmail())
            ->setFromAddress('{contactfield=sender}')
            ->setFromName('{contactfield=sender_name}');
        $transport = $this->createTransportWithEnvelopeAndEmailEntity($emailEntity, new Lead(), [
            'sender'      => 'database-sender@example.com',
            'sender_name' => 'Database Sender',
        ]);
        $message = (new MauticMessage())
            ->from(new Address('default@example.com', 'Default Sender'))
            ->sender(new Address('default@example.com', 'Default Sender'));

        $this->invokeUpdateEmailFields($transport, $message, [
            'emailId' => 123,
            'leadId'  => 456,
            'tokens'  => [],
        ]);

        $from = $message->getFrom()[0];
        $this->assertSame('database-sender@example.com', $from->getAddress());
        $this->assertSame('Database Sender', $from->getName());
        $this->assertSame('database-sender@example.com', $message->getSender()?->getAddress());
        $this->assertSame('Database Sender', $message->getSender()?->getName());
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

    private function createTransportWithEnvelopeAndEmailEntity(MauticEmail $emailEntity, ?Lead $lead = null, array $leadFieldValues = []): AmazonSesTransport
    {
        $transport = $this->createTransportWithEnvelope();
        $entityManager = new class($emailEntity, $lead, $leadFieldValues) {
            public function __construct(private MauticEmail $emailEntity, private ?Lead $lead, private array $leadFieldValues)
            {
            }

            public function getRepository(string $className): object
            {
                return new class($className, $this->emailEntity, $this->lead) {
                    public function __construct(private string $className, private MauticEmail $emailEntity, private ?Lead $lead)
                    {
                    }

                    public function find(int $id): MauticEmail|Lead|null
                    {
                        if (Lead::class === $this->className) {
                            return $this->lead;
                        }

                        return $this->emailEntity;
                    }
                };
            }

            public function getConnection(): object
            {
                return new class($this->leadFieldValues) {
                    public function __construct(private array $leadFieldValues)
                    {
                    }

                    public function quoteIdentifier(string $identifier): string
                    {
                        return '`'.$identifier.'`';
                    }

                    public function fetchOne(string $query, array $params): mixed
                    {
                        preg_match('/SELECT `([^`]+)` FROM/', $query, $matches);

                        return $this->leadFieldValues[$matches[1] ?? ''] ?? false;
                    }
                };
            }

            public function getClassMetadata(string $className): object
            {
                return new class {
                    public function getTableName(): string
                    {
                        return 'leads';
                    }
                };
            }
        };
        $reflection = new \ReflectionClass(AmazonSesTransport::class);
        $entityManagerProperty = $reflection->getProperty('entityManager');
        $entityManagerProperty->setAccessible(true);
        $entityManagerProperty->setValue($transport, $entityManager);

        return $transport;
    }

    private function invokeSetFrom(AmazonSesTransport $transport, MauticMessage $message, MauticEmail $emailEntity, array $mailData = []): void
    {
        $method = new \ReflectionMethod(AmazonSesTransport::class, 'setFrom');
        $method->setAccessible(true);
        $method->invoke($transport, $message, $emailEntity, $mailData);
    }

    private function invokeUpdateEmailFields(AmazonSesTransport $transport, MauticMessage $message, array $mailData = []): void
    {
        $method = new \ReflectionMethod(AmazonSesTransport::class, 'updateEmailFields');
        $method->setAccessible(true);
        $method->invoke($transport, $message, $mailData);
    }
}
