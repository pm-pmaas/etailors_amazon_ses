<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Tests\Unit\EventSubscriber;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\Event\TransportWebhookEvent;
use Mautic\EmailBundle\Model\EmailStatModel;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\EmailBundle\MonitoredEmail\Search\ContactFinder;
use Mautic\LeadBundle\Model\DoNotContact as DncModel;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\AmazonSesBundle\EventSubscriber\CallbackSubscriber;
use MauticPlugin\AmazonSesBundle\Helper\SnsWebhookAuthenticator;
use MauticPlugin\AmazonSesBundle\Mailer\Bulk\DeliveryStore;
use MauticPlugin\AmazonSesBundle\Tests\Unit\Mailer\Bulk\DeliveryStoreTest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class CallbackSubscriberTest extends TestCase
{
    private const TOPIC = 'arn:aws:sns:eu-central-1:1:topic';

    private DeliveryStore $store;
    /** @var CoreParametersHelper&MockObject */
    private CoreParametersHelper $parameters;
    /** @var DncModel&MockObject */
    private DncModel $dncModel;
    /** @var list<MockObject> collaborators of the real TransportCallback */
    private array $callbackCollaborators;
    private CallbackSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->store = new DeliveryStore(DeliveryStoreTest::manager());
        $this->store->install();
        $this->parameters = $this->createMock(CoreParametersHelper::class);
        $this->dncModel = $this->createMock(DncModel::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        // TransportCallback is final in Mautic 7, so it is built from mocked collaborators instead of mocked itself.
        $this->callbackCollaborators = [$this->createMock(DncModel::class), $this->createMock(ContactFinder::class), $this->createMock(EmailStatModel::class)];

        $this->subscriber = new CallbackSubscriber(
            new TransportCallback(...$this->callbackCollaborators),
            $this->parameters,
            $this->createMock(HttpClientInterface::class),
            $this->createMock(ContactFinder::class),
            $this->dncModel,
            $this->createMock(LeadModel::class),
            // SnsWebhookAuthenticator is final, so use the real one; webhook rejection fixtures omit the signature.
            new SnsWebhookAuthenticator(),
            $translator,
            new NullLogger(),
            $this->store,
        );
    }

    public function testAuthenticatedNotificationRecordsDeliveryEvent(): void
    {
        $id = $this->store->enqueue(DeliveryStoreTest::delivery(), 'scope');
        $this->store->complete($this->store->claim($id, 'scope', 'worker'), 'accepted', '', 'ses-id');

        $result = $this->subscriber->processJsonPayload(self::notification(['eventType' => 'Delivery', 'mail' => ['messageId' => 'ses-id', 'tags' => ['mautic_delivery_id' => [$id]]]]), 'Notification');

        self::assertSame(['hasError' => false, 'message' => 'PROCESSED'], $result);
        self::assertSame('delivered', $this->store->summary()['recipients'][0]['event']);
    }

    public function testNestedNotificationErrorPropagates(): void
    {
        // The inner type is read from notificationType (or eventType), so the nested Notification is marked that way.
        $result = $this->subscriber->processJsonPayload(self::notification(['notificationType' => 'Notification', 'Message' => 'not json']), 'Notification');

        self::assertTrue($result['hasError']);
        self::assertSame('mautic.amazonses.plugin.sns.callback.notification.json_invalid', $result['message']);
    }

    /**
     * @dataProvider sesEventTypesWithoutContactAction
     */
    public function testSesEventTypesWithoutContactActionAreProcessed(string $eventType): void
    {
        foreach ([...$this->callbackCollaborators, $this->dncModel] as $collaborator) {
            $collaborator->expects($this->never())->method($this->anything());
        }

        $result = $this->subscriber->processJsonPayload(self::notification(['eventType' => $eventType]), 'Notification');

        self::assertFalse($result['hasError']);
        self::assertSame('PROCESSED', $result['message']);
    }

    public static function sesEventTypesWithoutContactAction(): array
    {
        return [
            'Send'              => ['Send'],
            'Delivery'          => ['Delivery'],
            'Reject'            => ['Reject'],
            'Rendering Failure' => ['Rendering Failure'],
        ];
    }

    public function testUnauthenticatedWebhookIsRejectedBeforeRecordingEvents(): void
    {
        $this->parameters->method('get')->with('mailer_dsn')->willReturn('mautic+ses+api://key:secret@default?region=eu-central-1&sns_topic_arn='.self::TOPIC);
        $id = $this->store->enqueue(DeliveryStoreTest::delivery(), 'scope');
        // The configured topic passes the topic check; the missing SNS signature makes authentication fail.
        $payload = ['TopicArn' => self::TOPIC] + self::notification(['eventType' => 'Send', 'mail' => ['messageId' => 'ses-id', 'tags' => ['mautic_delivery_id' => [$id]]]]);
        $event = new TransportWebhookEvent(Request::create('/mailer/callback', 'POST', [], [], [], [], json_encode($payload, JSON_THROW_ON_ERROR)));

        $this->subscriber->processCallbackRequest($event);

        self::assertSame(403, $event->getResponse()?->getStatusCode());
        self::assertSame('', $this->store->summary()['recipients'][0]['event']);
    }

    /** @return array{Type: string, Message: string} */
    private static function notification(array $message): array
    {
        return ['Type' => 'Notification', 'Message' => json_encode($message, JSON_THROW_ON_ERROR)];
    }
}
