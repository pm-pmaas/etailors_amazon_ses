<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\EventSubscriber;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\TransportWebhookEvent;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\LeadBundle\Entity\DoNotContact;
use MauticPlugin\AmazonSesBundle\Mailer\Transport\AmazonSesTransport;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class CallbackSubscriber implements EventSubscriberInterface
{
    private static TranslatorInterface $translator;

    public function __construct(
        private TransportCallback $transportCallback,
        private CoreParametersHelper $coreParametersHelper,
        private HttpClientInterface $client,
        ?LoggerInterface $logger = null,
        TranslatorInterface $translator,
    ) {
        self::$translator = $translator;
        $this->logger     = $logger;
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            EmailEvents::ON_TRANSPORT_WEBHOOK => 'processCallbackRequest',
        ];
    }

    public function processCallbackRequest(TransportWebhookEvent $event): void
    {
        $dsn = Dsn::fromString($this->coreParametersHelper->get('mailer_dsn'));

        if (AmazonSesTransport::MAUTIC_AMAZONSES_API_SCHEME !== $dsn->getScheme()) {
            return;
        }

        $this->logger->info('PAYLOAPM RECIEVE');

        $payload = $event->getRequest()->toArray();

        if (array_key_exists('Type', $payload)) {
            $type = $payload['Type'];
        } elseif (array_key_exists('eventType', $payload)) {
            $type = $payload['eventType'];
        } else {
            throw new HttpException(400, "Key 'Type' not found in payload");
        }

        $this->processJsonPayload($payload, $type);
        $event->setResponse(new Response('Callback processed'));
    }

    /**
     * Process json request from Amazon SES.
     *
     * http://docs.aws.amazon.com/ses/latest/DeveloperGuide/best-practices-bounces-complaints.html
     *
     * @param array<string, mixed> $payload from Amazon SES
     */
    public function processJsonPayload(array $payload, string $type): void
    {
        switch ($type) {
            case 'SubscriptionConfirmation':
                // Confirm Amazon SNS subscription by calling back the SubscribeURL from the playload
                try {
                    $response = $this->client->request('GET', $payload['SubscribeURL']);
                    if (200 == $response->getStatusCode()) {
                        $this->logger->info('Callback to SubscribeURL from Amazon SNS successfully');
                    }
                } catch (TransferException $e) {
                    $this->logger->error('Callback to SubscribeURL from Amazon SNS failed, reason: '.$e->getMessage());
                }
                break;

            case 'Notification':
                try {
                    $message = json_decode($payload['Message'], true, 512, JSON_THROW_ON_ERROR);
                } catch (\Exception $e) {
                    $this->logger->error('AmazonCallback: Invalid Notification JSON Payload');
                    throw new HttpException(400, 'AmazonCallback: Invalid Notification JSON Payload');
                }

                $this->processJsonPayload($message, $message['notificationType']);
                break;
            case 'Complaint':
                foreach ($payload['complaint']['complainedRecipients'] as $complainedRecipient) {
                    $reason = null;
                    if (isset($payload['complaint']['complaintFeedbackType'])) {
                        // http://docs.aws.amazon.com/ses/latest/DeveloperGuide/notification-contents.html#complaint-object
                        switch ($payload['complaint']['complaintFeedbackType']) {
                            case 'abuse':
                                $reason = self::$translator->trans('mautic.amazonses.plugin.complaint.reason.abuse', [], 'validators');
                                break;
                            case 'auth-failure':
                                $reason = self::$translator->trans('mautic.amazonses.plugin.complaint.reason.auth_failure', [], 'validators');
                                break;
                            case 'fraud':
                                $reason = self::$translator->trans('mautic.amazonses.plugin.complaint.reason.fraud', [], 'validators');
                                break;
                            case 'not-spam':
                                $reason = self::$translator->trans('mautic.amazonses.plugin.complaint.reason.not_spam', [], 'validators');
                                break;
                            case 'other':
                                $reason = self::$translator->trans('mautic.amazonses.plugin.complaint.reason.other', [], 'validators');
                                break;
                            case 'virus':
                                $reason = self::$translator->trans('mautic.amazonses.plugin.complaint.reason.virus', [], 'validators');
                                break;
                            default:
                                $reason = self::$translator->trans('mautic.amazonses.plugin.complaint.reason.unknown', [], 'validators');
                                break;
                        }
                    }

                    if (null === $reason) {
                        if (empty($payload['complaint']['complaintSubType'])) {
                            $reason = self::$translator->trans('mautic.amazonses.plugin.complaint.reason.unknown', [], 'validators');
                        } else {
                            $reason = $payload['complaint']['complaintSubType'];
                        }
                    }

                    $emailId = null;

                    if (isset($payload['mail']['headers'])) {
                        foreach ($payload['mail']['headers'] as $header) {
                            if ('X-EMAIL-ID' === $header['name']) {
                                $emailId = $header['value'];
                            }
                        }
                    }

                    $this->transportCallback->addFailureByAddress($complainedRecipient['emailAddress'], $reason, DoNotContact::UNSUBSCRIBED, $emailId);
                    $this->logger->debug("Unsubscribe email '".$complainedRecipient['emailAddress']."'");
                }

                break;
            case 'Bounce':
                if ('Permanent' == $payload['bounce']['bounceType']) {
                    $emailId = null;

                    if (isset($payload['mail']['headers'])) {
                        foreach ($payload['mail']['headers'] as $header) {
                            if ('X-EMAIL-ID' === $header['name']) {
                                $emailId = $header['value'];
                            }
                        }
                    }

                    // Get bounced recipients in an array
                    $bouncedRecipients = $payload['bounce']['bouncedRecipients'];
                    foreach ($bouncedRecipients as $bouncedRecipient) {
                        $bounceCode = array_key_exists('diagnosticCode', $bouncedRecipient) ? $bouncedRecipient['diagnosticCode'] : 'unknown';
                        $bounceCode .= ' AWS bounce type: '.$payload['bounce']['bounceType'].' bounce subtype:'.$payload['bounce']['bounceSubType'];
                        $this->transportCallback->addFailureByAddress($bouncedRecipient['emailAddress'], $bounceCode, DoNotContact::BOUNCED, $emailId);
                        $this->logger->debug("Mark email '".$bouncedRecipient['emailAddress']."' as bounced, reason: ".$bounceCode);
                    }
                }
                break;
            default:
                $this->logger->warning('Received SES webhook of type '.$payload['Type']." but couldn't understand payload");
                $this->logger->debug('SES webhook payload: '.json_encode($payload));
                throw new HttpException(400, "Received SES webhook of type '$payload[Type]' but couldn't understand payload");
        }
    }
}
