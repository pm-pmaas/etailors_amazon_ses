<?php
/*
 * @copyright       (c) 2024. e-tailors IP B.V. All rights reserved
 * @author          Paul Maas <p.maas@e-tailors.com>
 *
 * @link            https://www.e-tailors.com
 */

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\EventSubscriber;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\TransportWebhookEvent;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\EmailBundle\MonitoredEmail\Search\ContactFinder;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\LeadBundle\Entity\DoNotContact as DNC;
use MauticPlugin\AmazonSesBundle\Mailer\Transport\AmazonSesTransport;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Mautic\LeadBundle\Model\DoNotContact as DncModel;

class CallbackSubscriber implements EventSubscriberInterface
{
    private TranslatorInterface $translator;
    /**
     * @var LoggerInterface|null
     */
    private $logger;

    public function __construct(
        private TransportCallback                     $transportCallback,
        private CoreParametersHelper                  $coreParametersHelper,
        private HttpClientInterface                   $client,
        TranslatorInterface                           $translator,
        ?LoggerInterface                              $logger = null,
        private ContactFinder                         $finder,
        private DncModel $dncModel,
    ) {
        $this->translator = $translator;
        $this->logger     = $logger;
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            EmailEvents::ON_TRANSPORT_WEBHOOK => ['processCallbackRequest', 0],
        ];
    }

    public function processCallbackRequest(TransportWebhookEvent $event): void
    {
        $dsn = Dsn::fromString($this->coreParametersHelper->get('mailer_dsn'));

        if (AmazonSesTransport::MAUTIC_AMAZONSES_API_SCHEME !== $dsn->getScheme()) {
            return;
        }

        $this->logger->debug('start processCallbackRequest - Amazon SNS Webhook');

        try {
            $snsreq  = $event->getRequest();
            $payload = json_decode($snsreq->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Exception $e) {
            $this->logger->error('SNS: Invalid JSON Payload');
            $event->setResponse(
                $this->createResponse(
                    $this->translator->trans('mautic.amazonses.plugin.sns.callback.json.invalid', [], 'validators'),
                    false
                )
            );

            return;
        }

        if (0 !== json_last_error()) {
            $event->setResponse(
                $this->createResponse(
                    $this->translator->trans('mautic.amazonses.plugin.sns.callback.json.invalid', [], 'validators'),
                    false
                )
            );

            return;
        }

        $type = '';
        if (array_key_exists('Type', $payload)) {
            $type = $payload['Type'];
        } elseif (array_key_exists('eventType', $payload)) {
            $type = $payload['eventType'];
        } elseif (array_key_exists('notificationType', $payload)) {
            $type = $payload['notificationType'];
        } else {
            $event->setResponse(
                $this->createResponse(
                    $this->translator->trans('mautic.amazonses.plugin.sns.callback.json.invalid_payload_type', [], 'validators'),
                    false
                )
            );

            return;
        }

        $proces_json_res = $this->processJsonPayload($payload, $type);

        if (true == $proces_json_res['hasError']) {
            $eventResponse = $this->createResponse($proces_json_res['message'], false);
        } else {
            $eventResponse = $this->createResponse($proces_json_res['message'], true);
        }

        $this->logger->debug('end processCallbackRequest - Amazon SNS Webhook');
        $event->setResponse($eventResponse);
    }

    /**
     * @return Response
     */
    private function createResponse($message, $success)
    {
        if (false == $success) {
            $statusCode = Response::HTTP_BAD_REQUEST;
        } else {
            $statusCode = Response::HTTP_OK;
        }

        return new Response(
            json_encode([
                'message' => $message,
                'success' => $success,
            ]),
            $statusCode,
            ['content-type' => 'application/json']
        );
    }

    /**
     * Process json request from Amazon SES.
     *
     * http://docs.aws.amazon.com/ses/latest/DeveloperGuide/best-practices-bounces-complaints.html
     *
     * @param array<string, mixed> $payload from Amazon SES
     */
    public function processJsonPayload(array $payload, $type): array
    {
        $typeFound = false;
        $hasError  = false;
        $message   = 'PROCESSED';
        switch ($type) {
            case 'SubscriptionConfirmation':
                $typeFound = true;

                $reason = null;

                // Confirm Amazon SNS subscription by calling back the SubscribeURL from the playload
                try {
                    $response = $this->client->request('GET', $payload['SubscribeURL']);
                    if (200 == $response->getStatusCode()) {
                        $this->logger->info('Callback to SubscribeURL from Amazon SNS successfully');
                        break;
                    } else {
                        $reason = 'HTTP Code '.$response->getStatusCode().', '.$response->getContent();
                    }
                } catch (TransportExceptionInterface $e) {
                    $reason = $e->getMessage();
                }

                if (null !== $reason) {
                    $this->logger->error(
                        'Callback to SubscribeURL from Amazon SNS failed, reason: ',
                        ['reason' => $reason]
                    );

                    $hasError = true;
                    $message  = $this->translator->trans('mautic.amazonses.plugin.sns.callback.subscribe.error', [], 'validators');
                }

                break;

            case 'Notification':
                $typeFound = true;

                try {
                    $message = json_decode($payload['Message'], true, 512, JSON_THROW_ON_ERROR);
                    $this->processJsonPayload($message, $message['notificationType']);
                } catch (\Exception $e) {
                    $this->logger->error('AmazonCallback: Invalid Notification JSON Payload');
                    $hasError = true;
                    $message  = $this->translator->trans('mautic.amazonses.plugin.sns.callback.notification.json_invalid', [], 'validators');
                }

                break;

            case 'Delivery':
                // Nothing more to do here.
                $typeFound = true;

                break;

            case 'Complaint':
                $typeFound = true;

                $emailId = $this->getEmailHeader($payload);

                // Get bounced recipients in an array
                $complaintRecipients = $payload['complaint']['complainedRecipients'];
                foreach ($complaintRecipients as $complaintRecipient) {
                    // http://docs.aws.amazon.com/ses/latest/DeveloperGuide/notification-contents.html#complaint-object
                    // abuse / auth-failure / fraud / not-spam / other / virus
                    $complianceCode = array_key_exists('complaintFeedbackType', $payload['complaint']) ? $payload['complaint']['complaintFeedbackType'] : 'unknown';
                    $this->transportCallback->addFailureByAddress($this->cleanupEmailAddress($complaintRecipient['emailAddress']), $complianceCode, DoNotContact::UNSUBSCRIBED, $emailId);
                    $this->logger->debug("Mark email '".$complaintRecipient['emailAddress']."' has complained, reason: ".$complianceCode);
                }
                break;

            case 'Bounce':
                $typeFound = true;
                $this->addFailureByAddressCustom($payload);
                break;
            default:
                $this->logger->warning(
                    'SES webhook payload, not processed due to unknown type.',
                    ['Type' => $payload['Type'], 'payload' => json_encode($payload)]
                );
                break;
        }

        if (!$typeFound) {
            $message = sprintf(
                $message = $this->translator->trans('mautic.amazonses.plugin.sns.callback.unkown_type', [], 'validators'),
                $type
            );
        }

        return [
            'hasError' => $hasError,
            'message'  => $message,
        ];
    }

    private function addFailureByAddressCustom(array $payload): void
    {
        $type = $payload['bounce']['bounceType'];
        $typeName = 'OTHER';
        $channel = 'email';
        if ('Permanent' === $type) {
            $typeName = 'HARD';
        } elseif ('Transient' === $type) {
            $typeName = 'SOFT';
            $channel = 'AWS';
        }
        $emailId = $this->getEmailHeader($payload);
        $bouncedRecipients = $payload['bounce']['bouncedRecipients'];
        foreach ($bouncedRecipients as $bouncedRecipient) {
            $bounceSubType = $payload['bounce']['bounceSubType'];
            $bounceDiagnostic = array_key_exists('diagnosticCode', $bouncedRecipient) ? $bouncedRecipient['diagnosticCode'] : 'unknown';
            $bounceCode = $typeName.': AWS: '.$bounceSubType.': '.$bounceDiagnostic;
            $channelWithId = [$channel => $emailId];
            $this->addFailureByAddress($this->cleanupEmailAddress($bouncedRecipient['emailAddress']),$bounceCode,DoNotContact::BOUNCED,$channelWithId);
            $this->logger->debug("Mark email '" . $bouncedRecipient['emailAddress'] . "' as bounced, reason: " . $bounceCode);
        }
    }

    public function cleanupEmailAddress($email)
    {
        return preg_replace('/(.*)<(.*)>(.*)/s', '\2', $email);
    }

    public function getEmailHeader($payload)
    {
        if (!isset($payload['mail']['headers'])) {
            return null;
        }

        foreach ($payload['mail']['headers'] as $header) {
            if ('X-EMAIL-ID' === strtoupper($header['name'])) {
                return $header['value'];
            }
        }
    }

    /**
     * @param string   $address
     * @param string   $comments
     * @param int      $dncReason
     * @param int|null $channelId
     */
    public function addFailureByAddress($address, $comments, $dncReason = DNC::BOUNCED, $channel = null): void
    {
        $result = $this->finder->findByAddress($address);

        if ($contacts = $result->getContacts()) {
            foreach ($contacts as $contact) {
                $channel = ($channel) ?: 'email';
                $this->dncModel->addDncForContact($contact->getId(), $channel, $dncReason, $comments);
            }
        }
    }
}
