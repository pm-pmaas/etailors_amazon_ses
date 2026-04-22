<?php

namespace MauticPlugin\AmazonSesBundle\Tests\Unit\Mailer\Transport;

use Aws\SesV2\SesV2Client;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\PathsHelper;
use Mautic\EmailBundle\Mailer\Message\MauticMessage;
use MauticPlugin\AmazonSesBundle\Mailer\Transport\AmazonSesTransport;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;

class AmazonSesTransportTest extends TestCase
{
    public function testListUnsubscribeHeadersArePreserved()
    {
        $amazonClient = $this->createMock(SesV2Client::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $pathsHelper = $this->createMock(PathsHelper::class);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $settings = ['maxSendRate' => 14];

        $transport = new AmazonSesTransport(
            $amazonClient,
            $entityManager,
            $pathsHelper,
            $dispatcher,
            $logger,
            $settings
        );

        $message = new MauticMessage();
        $message->from(new Address('from@example.com', 'From Name'));
        $message->to(new Address('to@example.com', 'To Name'));
        $message->subject('Test Subject');
        $message->html('Test Body');
        
        $message->getHeaders()->addTextHeader('List-Unsubscribe', '<mailto:unsubscribe@example.com>, <https://example.com/unsubscribe>');
        $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        // Setup transport state
        $refMessage = new \ReflectionProperty(AmazonSesTransport::class, 'message');
        $refMessage->setAccessible(true);
        $refMessage->setValue($transport, $message);

        // Case 1: No metadata, no tokens
        $payloads = iterator_to_array($transport->convertMessageToRawPayload());
        $this->assertCount(1, $payloads);
        $rawPayload = preg_replace('/\s+/', ' ', $payloads[0]['Content']['Raw']['Data']);
        $this->assertStringContainsString('List-Unsubscribe: <mailto:unsubscribe@example.com>, <https://example.com/unsubscribe>', $rawPayload);
        $this->assertStringContainsString('List-Unsubscribe-Post: List-Unsubscribe=One-Click', $rawPayload);

        // Case 2: With metadata and {unsubscribe_url} token
        $recipientMetadata = [
            'tokens' => ['{unsubscribe_url}' => 'https://example.com/token-unsubscribe'],
            'name' => 'To Name',
            'hashId' => 'hash123',
            'contactId' => 1,
        ];
        $message->addMetadata('to@example.com', $recipientMetadata);
        
        $payloads = iterator_to_array($transport->convertMessageToRawPayload());
        $this->assertCount(1, $payloads);
        $rawPayload = $payloads[0]['Content']['Raw']['Data'];
        
        // It should have replaced List-Unsubscribe with the token value
        $this->assertStringContainsString('List-Unsubscribe: <https://example.com/token-unsubscribe>', $rawPayload);
        $this->assertStringNotContainsString('List-Unsubscribe: <mailto:unsubscribe@example.com>', $rawPayload);
        $this->assertStringContainsString('List-Unsubscribe-Post: List-Unsubscribe=One-Click', $rawPayload);
    }
}
