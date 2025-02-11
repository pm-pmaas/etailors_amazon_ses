<?php
declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Mailer\Factory;

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use Aws\SesV2\SesV2Client;
use Doctrine\ORM\EntityManagerInterface;
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

class AmazonSesTransportFactory extends AbstractTransportFactory
{
    private static SesV2Client $amazonclient;
    private static TranslatorInterface $translator;

    private EntityManagerInterface $entityManager;

    public function __construct(
        TransportCallback $transportCallback,
        EventDispatcherInterface $eventDispatcher,
        TranslatorInterface $translator,
        EntityManagerInterface $entityManager,
        ?LoggerInterface $logger = null,
        ?SesV2Client $amazonclient = null
    ) {
        parent::__construct($eventDispatcher, $amazonclient, $logger);
        self::$translator = $translator;
        $this->entityManager = $entityManager;
    }

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

            $cacheFile = sys_get_temp_dir() . '/ses_send_quota.json';
            $cacheTTL  = 3600; // 60 minutes

            // read cache with lock
            if (file_exists($cacheFile)) {
                $handle = fopen($cacheFile, 'r');
                if ($handle && flock($handle, LOCK_SH)) {
                    $data = json_decode(fread($handle, filesize($cacheFile)), true);
                    flock($handle, LOCK_UN);
                    fclose($handle);

                    if (isset($data['maxSendRate']) && (time() - $data['timestamp']) < $cacheTTL) {
                        $this->logger->debug('maxSendRate from cache ' . $data['maxSendRate']);
                        return new AmazonSesTransport(
                            $client,
                            $this->entityManager,
                            $this->dispatcher,
                            $this->logger,
                            ['maxSendRate' => (int) $data['maxSendRate']]
                        );
                    }
                }
            }

            try {
                $account = $client->getAccount();
                $maxSendRate = (int) floor($account->get('SendQuota')['MaxSendRate']);
                $this->logger->debug('maxSendRate from request ' . $maxSendRate);

                // cache the maxSendRate
                $data = json_encode(['maxSendRate' => $maxSendRate, 'timestamp' => time()]);
                file_put_contents($cacheFile, $data, LOCK_EX);

            } catch (\Exception $e) {
                $this->logger->error($e->getMessage());

                // fallback to previous cached maxSendRate
                if (isset($data['maxSendRate'])) {
                    $maxSendRate = (int) $data['maxSendRate'];
                    $this->logger->debug('Using previous cached maxSendRate: ' . $maxSendRate);
                } else {
                    $maxSendRate = 14; // standard fallback rate
                }
            }

            return new AmazonSesTransport(
                $client,
                $this->entityManager,
                $this->dispatcher,
                $this->logger,
                ['maxSendRate' => $maxSendRate]
            );
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

        $dsn_password = self::sanitizePassword($dsn_password);

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

    /**
     * Sanitize the password by stripping out unwanted characters like HTML icons.
     *
     * @param string $password
     * @return string
     */
    private static function sanitizePassword(string $password): string
    {
        // Strip HTML tags and any encoded entities.
        $cleanPassword = strip_tags($password);

        // Optionally, remove other unwanted characters, e.g., non-printable ASCII.
        // This regular expression will strip any non-ASCII characters.
        $cleanPassword = preg_replace('/[^\x20-\x7E]/', '', $cleanPassword);

        // Trim extra spaces that might get inserted accidentally.
        return trim($cleanPassword);
    }
}
