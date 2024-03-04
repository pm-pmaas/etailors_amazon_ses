<?php

declare(strict_types=1);
namespace MauticPlugin\AmazonSesBundle\Mailer\Factory;

use Mautic\EmailBundle\Model\TransportCallback;
use MauticPlugin\AmazonSesBundle\Mailer\Transport\AmazonSesTransport;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Exception\IncompleteDsnException;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use Aws\SesV2\SesV2Client;

class AmazonSesTransportFactory extends AbstractTransportFactory
{

    private static SesV2Client $amazonclient;
    private static TranslatorInterface $translator;

    public function __construct(
        private TransportCallback $transportCallback,
        EventDispatcherInterface $eventDispatcher,
        SesV2Client $amazonclient = null,
        TranslatorInterface $translator,
        LoggerInterface $logger = null
    ) {
        parent::__construct($eventDispatcher, $amazonclient, $logger);
        self::$translator = $translator;

    }

    /**
     * @return string[]
     */
    protected function getSupportedSchemes(): array
    {
        return [AmazonSesTransport::MAUTIC_AMAZONSES_API_SCHEME];
    }

    public function create(Dsn $dsn): TransportInterface
    {
        if (AmazonSesTransport::MAUTIC_AMAZONSES_API_SCHEME === $dsn->getScheme()) {
            self::initAmazonClient($dsn);
            $client  = self::getAmazonClient();

            try {
                $account           = $client->getAccount();
                $maxSendRate       = (int) floor($account->get('SendQuota')['MaxSendRate']);
                $settings = ['maxSendRate' => $maxSendRate ];
            } catch (\Exception $e) {
                $this->logger->debug($e->getMessage());
                $settings = ['maxSendRate' => 14 ];
            }
            return new AmazonSesTransport($client,$this->dispatcher, $this->logger, $settings);
        }

        throw new UnsupportedSchemeException($dsn, 'Amazon SES', $this->getSupportedSchemes());
    }

    public static function getAmazonClient(): SesV2Client
    {
        if (!isset(self::$amazonclient)) {
           // throw new IncompleteDsnException('clientnotset');
            throw new IncompleteDsnException(self::$translator->trans('mautic.amazonses.plugin.amazonclient.notset', [], 'validators'));
        }
        return self::$amazonclient;
    }

    public static function initAmazonClient(Dsn $dsn, \Countable $handler=null): void
    {
        $dsn_user = $dsn->getUser();
        if (null === $dsn_user) {
            throw new IncompleteDsnException(self::$translator->trans('mautic.amazonses.plugin.user.empty', [], 'validators'));
        }

        $dsn_password = $dsn->getPassword();
        if (null === $dsn_password) {
            throw new IncompleteDsnException(self::$translator->trans('mautic.amazonses.plugin.password.empty', [], 'validators'));
        }

        if (!$dsn_region = $dsn->getOption('region')) {
            throw new IncompleteDsnException(self::$translator->trans('mautic.amazonses.plugin.region.empty', [], 'validators'));
        }

        if (!array_key_exists($dsn_region, AmazonSesTransport::AMAZON_REGION)) {
            throw new InvalidArgumentException(self::$translator->trans('mautic.amazonses.plugin.region.invalid', [], 'validators'));
        }

        $ratelimit = $dsn->getOption('ratelimit');
        if ($ratelimit  !== null AND !is_numeric($dsn->getOption('ratelimit')) ) {
            throw new InvalidArgumentException(self::$translator->trans('mautic.amazonses.plugin.ratelimit.invalid', [], 'validators'));
        }

        if (!isset(self::$amazonclient)) {
            $config = [
                'version'               => 'latest',
                'credentials'           => CredentialProvider::fromCredentials(new Credentials($dsn_user, $dsn_password)),
                'region'                => $dsn_region,
            ];

            if ($handler) {
                $config['handler'] = $handler;
            }

            /**
             * Check singleton
             */
            self::$amazonclient = new SesV2Client($config);
        }
    }

}
