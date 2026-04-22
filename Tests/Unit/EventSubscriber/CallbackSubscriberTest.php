<?php

namespace MauticPlugin\AmazonSesBundle\Tests\Unit\EventSubscriber;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\Event\TransportWebhookEvent;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\EmailBundle\MonitoredEmail\Search\ContactFinder;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\LeadBundle\Model\DoNotContact as DncModel;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\AmazonSesBundle\EventSubscriber\CallbackSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class CallbackSubscriberTest extends TestCase
{
    private $transportCallback;
    private $coreParametersHelper;
    private $httpClient;
    private $contactFinder;
    private $dncModel;
    private $leadModel;
    private $translator;
    private $logger;
    private CallbackSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->transportCallback = $this->createMock(TransportCallback::class);
        $this->coreParametersHelper = $this->createMock(CoreParametersHelper::class);
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->contactFinder = $this->createMock(ContactFinder::class);
        $this->dncModel = $this->createMock(DncModel::class);
        $this->leadModel = $this->createMock(LeadModel::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->subscriber = new CallbackSubscriber(
            $this->transportCallback,
            $this->coreParametersHelper,
            $this->httpClient,
            $this->contactFinder,
            $this->dncModel,
            $this->leadModel,
            $this->translator,
            $this->logger
        );
    }

    public function testProcessCallbackRequestWithSesV2Bounce()
    {
        $this->coreParametersHelper->method('get')->with('mailer_dsn')->willReturn('mautic+ses+api://key:secret@default');

        $innerMessage = [
            'eventType' => 'Bounce',
            'bounce' => [
                'bounceType' => 'Permanent',
                'bounceSubType' => 'General',
                'bouncedRecipients' => [['emailAddress' => 'bounced@example.com']],
            ],
            'mail' => ['messageId' => 'test-id', 'headers' => [['name' => 'X-Email-ID', 'value' => '123']]],
        ];

        $payload = ['Type' => 'Notification', 'Message' => json_encode($innerMessage)];
        $event = new TransportWebhookEvent(new Request([], [], [], [], [], [], json_encode($payload)));

        $this->subscriber->processCallbackRequest($event);
        $this->assertEquals(200, $event->getResponse()->getStatusCode());
    }

    public function testProcessCallbackRequestWithSesV1Bounce()
    {
        $this->coreParametersHelper->method('get')->with('mailer_dsn')->willReturn('mautic+ses+api://key:secret@default');

        $innerMessage = [
            'notificationType' => 'Bounce',
            'bounce' => [
                'bounceType' => 'Permanent',
                'bounceSubType' => 'General',
                'bouncedRecipients' => [['emailAddress' => 'bounced@example.com']],
            ],
            'mail' => ['messageId' => 'test-id', 'headers' => [['name' => 'X-Email-ID', 'value' => '123']]],
        ];

        $payload = ['Type' => 'Notification', 'Message' => json_encode($innerMessage)];
        $event = new TransportWebhookEvent(new Request([], [], [], [], [], [], json_encode($payload)));

        $this->subscriber->processCallbackRequest($event);
        $this->assertEquals(200, $event->getResponse()->getStatusCode());
    }

    public function testProcessCallbackRequestWithSesV2Complaint()
    {
        $this->coreParametersHelper->method('get')->with('mailer_dsn')->willReturn('mautic+ses+api://key:secret@default');

        $innerMessage = [
            'eventType' => 'Complaint',
            'complaint' => [
                'complainedRecipients' => [['emailAddress' => 'complaint@example.com']],
                'complaintFeedbackType' => 'abuse',
            ],
            'mail' => ['messageId' => 'test-id', 'headers' => [['name' => 'X-Email-ID', 'value' => '789']]],
        ];

        $payload = ['Type' => 'Notification', 'Message' => json_encode($innerMessage)];
        $event = new TransportWebhookEvent(new Request([], [], [], [], [], [], json_encode($payload)));

        $this->subscriber->processCallbackRequest($event);
        $this->assertEquals(200, $event->getResponse()->getStatusCode());
    }
}
