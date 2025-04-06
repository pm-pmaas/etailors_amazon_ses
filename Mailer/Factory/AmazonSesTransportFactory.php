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
use Symfony\Component\DependencyInjection\ContainerInterface;
use Mautic\CoreBundle\Helper\PathsHelper;

class AmazonSesTransportFactory extends AbstractTransportFactory
{
    private static SesV2Client $amazonclient;
    private static TranslatorInterface $translator;
    private PathsHelper $pathsHelper;
    private EntityManagerInterface $entityManager;

    public function __construct(
        TransportCallback $transportCallback,
        EventDispatcherInterface $eventDispatcher,
        TranslatorInterface $translator,
        EntityManagerInterface $entityManager,
        PathsHelper $pathsHelper,
        ?LoggerInterface $logger = null,
        ?SesV2Client $amazonclient = null
    ) {
        parent::__construct($eventDispatcher, $amazonclient, $logger);
        self::$translator = $translator;
        $this->entityManager = $entityManager;
        $pathsHelper,
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
    
            $manualRate = $dsn->getOption('ratelimit');
    
            $cacheFile = $this->getCachePath();
            $cacheTTL = 3600; // 60 minutes
    
            $cachedRate = $this->getCachedSendRate($cacheFile, $cacheTTL);
    
            try {
                $account = $client->getAccount();
                $fetchedRate = (int)floor($account->get('SendQuota')['MaxSendRate']);
                $this->saveSendRateToCache($cacheFile, $fetchedRate);
            } catch (\Exception $e) {
                $this->logger?->error('SES quota fetch failed: ' . $e->getMessage());
                $fetchedRate = $this->getCachedSendRate($cacheFile, PHP_INT_MAX) ?? null;
            }
    
            $effectiveRate = (int)($manualRate ?? $cachedRate ?? $fetchedRate ?? 14);
    
            return new AmazonSesTransport(
                $client,
                $this->entityManager,
                $this->dispatcher,
                $this->logger,
                ['maxSendRate' => $effectiveRate]
            );
        }

        throw new UnsupportedSchemeException($dsn, 'Amazon SES', $this->getSupportedSchemes());
    }

    /**
     * Reads the cached send rate from a file, ensuring safe access with a file lock.
     *
     * @param string $cacheFile
     * @param int $cacheTTL
     * @return int|null
     */
    private function getCachedSendRate(string $cacheFile, int $cacheTTL): ?int
    {
        if (!file_exists($cacheFile)) {
            return null;
        }

        $handle = fopen($cacheFile, 'r');
        if (!$handle || !flock($handle, LOCK_SH)) {
            return null;
        }

        $data = json_decode(fread($handle, filesize($cacheFile)), true);
        flock($handle, LOCK_UN);
        fclose($handle);

        if (!isset($data['maxSendRate'], $data['timestamp'])) {
            return null;
        }

        return (time() - $data['timestamp'] < $cacheTTL)
            ? (int)$data['maxSendRate']
            : null;
    }

    /**
     * Saves the send rate to a cache file with an atomic write operation.
     *
     * @param string $cacheFile
     * @param int $maxSendRate
     * @return void
     */
     private function saveSendRateToCache(string $cacheFile, int $maxSendRate): void
     {
        $cacheDir = dirname($cacheFile);
    
        if (!is_writable($cacheDir)) {
            $this->logger?->error("SES quota cache dir not writable: $cacheDir");
            return;
        }
    
        $tempFile = tempnam($cacheDir, 'ses_');
        if ($tempFile === false) {
            $this->logger?->error("Failed to create temp file in: $cacheDir");
            return;
        }
    
        $jsonData = json_encode([
            'maxSendRate' => $maxSendRate,
            'timestamp' => time()
        ]);
    
        if (@file_put_contents($tempFile, $jsonData) === false) {
            $this->logger?->error("Failed to write SES cache to: $tempFile");
            return;
        }
    
        if (!@rename($tempFile, $cacheFile)) {
            $this->logger?->error("Failed to move temp SES cache to: $cacheFile");
        }
     }

     private function getCachePath(): string
     {
            return $this->pathsHelper->getSystemPath('cache', true) . '/ses_send_quota.json';
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
                'use_aws_shared_config_files' => false,
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
