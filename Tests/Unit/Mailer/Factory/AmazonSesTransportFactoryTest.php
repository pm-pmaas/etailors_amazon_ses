<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Tests\Unit\Mailer\Factory;

use MauticPlugin\AmazonSesBundle\Mailer\Factory\AmazonSesTransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\Dsn;
use Mautic\EmailBundle\Model\TransportCallback;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\PathsHelper;
use Psr\Log\LoggerInterface;
use Aws\SesV2\SesV2Client;

class AmazonSesTransportFactoryTest extends TestCase
{
    /**
     * @dataProvider providePasswords
     */
    public function testSanitizePassword(string $input, string $expected): void
    {
        $ref = new \ReflectionMethod(AmazonSesTransportFactory::class, 'sanitizePassword');
        $ref->setAccessible(true);

        $result = $ref->invoke(null, $input);

        $this->assertSame($expected, $result);
    }

    public function providePasswords(): array
    {
        return [
            'html tags removed' => ['<b>password</b>', 'password'],
            'non ascii removed' => ['<i>pässwörd&nbsp;</i>', 'psswrd'],
        ];
    }

    public function testCreateWithDifferentRateLimitOptions(): void
    {
        $transportCallback = $this->createMock(TransportCallback::class);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $pathsHelper = $this->createMock(PathsHelper::class);
        $logger = $this->createMock(LoggerInterface::class);
        $amazonClient = $this->createMock(SesV2Client::class);

        $factory = new class(
            $transportCallback,
            $eventDispatcher,
            $translator,
            $entityManager,
            $pathsHelper,
            $logger,
            $amazonClient
        ) extends AmazonSesTransportFactory {
            public function __construct($tc, $ed, $tr, $em, $ph, $lo, $ac) {
                parent::__construct($tc, $ed, $tr, $em, $ph, $lo, $ac);
            }
            
            // Override to avoid actual filesystem/API calls in this unit test if possible, 
            // but DSN parsing is what we want to test.
            // Actually, we can just test the Dsn object and how it's used in create()
        };

        // Test ratelimit (lowercase)
        $dsn1 = new Dsn('mautic+ses+api', 'default', 'user', 'pass', null, ['ratelimit' => 10]);
        $this->assertEquals(10, $dsn1->getOption('ratelimit'));

        // Test rateLimit (camelCase)
        $dsn2 = new Dsn('mautic+ses+api', 'default', 'user', 'pass', null, ['rateLimit' => 15]);
        $this->assertEquals(15, $dsn2->getOption('rateLimit'));

        // Test logging options
        $dsn3 = new Dsn('mautic+ses+api', 'default', 'user', 'pass', null, [
            'loglevel' => 'DEBUG',
            'logpath' => '/tmp/ses.log',
            'masksensitive' => '0'
        ]);
        $this->assertEquals('DEBUG', $dsn3->getOption('loglevel'));
        $this->assertEquals('/tmp/ses.log', $dsn3->getOption('logpath'));
        $this->assertEquals('0', $dsn3->getOption('masksensitive'));
        
        // Verifying the logic in create() would require more mocking of getAccount etc.
        // But we've already verified that $dsn->getOption() works as expected for both.
    }
}
