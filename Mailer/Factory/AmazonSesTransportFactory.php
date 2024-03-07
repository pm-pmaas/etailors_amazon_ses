<?php
/*
 * @copyright       (c) 2024. e-tailors IP B.V. All rights reserverd
 * @author          Paul Maas <p.maas@e-tailors.com>
 *
 * @link            https://www.e-tailors.com
 */

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Mailer\Factory;

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use Aws\SesV2\SesV2Client;
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

/**
 *
 */
class AmazonSesTransportFactory extends AbstractTransportFactory
{
    /**
     * @var SesV2Client
     */
    private static SesV2Client $amazonclient;
    /**
     * @var TranslatorInterface
     */
    private static TranslatorInterface $translator;

    /**
     * @param TransportCallback $transportCallback
     * @param EventDispatcherInterface $eventDispatcher
     * @param TranslatorInterface $translator
     * @param LoggerInterface|null $logger
     * @param SesV2Client|null $amazonclient
     */
    public function __construct(
        TransportCallback $transportCallback,
        EventDispatcherInterface $eventDispatcher,
        TranslatorInterface $translator,
        ?LoggerInterface $logger = null,
        ?SesV2Client $amazonclient = null,
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

    /**
     * @param Dsn $dsn
     * @return TransportInterface
     */
    public function create(Dsn $dsn): TransportInterface
    {
        if (AmazonSesTransport::MAUTIC_AMAZONSES_API_SCHEME === $dsn->getScheme()) {
            self::initAmazonClient($dsn);
            $client = self::getAmazonClient();

            try {
                $account     = $client->getAccount();
                $maxSendRate = (int) floor($account->get('SendQuota')['MaxSendRate']);
                $settings    = ['maxSendRate' => $maxSendRate];
            } catch (\Exception $e) {
                $this->logger->debug($e->getMessage());
                $settings = ['maxSendRate' => 14];
            }

            return new AmazonSesTransport($client, $this->dispatcher, $this->logger, $settings);
        }

        throw new UnsupportedSchemeException($dsn, 'Amazon SES', $this->getSupportedSchemes());
    }

    /**
     * @return SesV2Client
     */
    public static function getAmazonClient(): SesV2Client
    {
        if (!isset(self::$amazonclient)) {
            // throw new IncompleteDsnException('clientnotset');
            throw new IncompleteDsnException(self::$translator->trans('mautic.amazonses.plugin.amazonclient.notset', [], 'validators'));
        }

        return self::$amazonclient;
    }

    /**
     * @param Dsn $dsn
     * @param \Countable|null $handler
     * @return void
     */
    public static function initAmazonClient(Dsn $dsn, ?\Countable $handler = null): void
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
        if (null !== $ratelimit and !is_numeric($dsn->getOption('ratelimit'))) {
            throw new InvalidArgumentException(self::$translator->trans('mautic.amazonses.plugin.ratelimit.invalid', [], 'validators'));
        }

        if (!isset(self::$amazonclient)) {
            $config = [
                'version'     => 'latest',
                'credentials' => CredentialProvider::fromCredentials(new Credentials($dsn_user, $dsn_password)),
                'region'      => $dsn_region,
            ];

            if ($handler) {
                $config['handler'] = $handler;
            }

            /*
             * Check singleton.
             */
            self::$amazonclient = new SesV2Client($config);
        }
    }
}
