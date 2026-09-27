<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Tests\Unit\Mailer\Transport;

use Mautic\EmailBundle\Mailer\Message\MauticMessage;
use MauticPlugin\AmazonSesBundle\Mailer\Transport\AmazonSesTransport;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Exception\TransportException;

class AmazonSesTransportTest extends TestCase
{
    public function testAddsMauticEmailIdToSesTags(): void
    {
        $reflection = new \ReflectionClass(AmazonSesTransport::class);
        $transport = $reflection->newInstanceWithoutConstructor();
        $message = (new MauticMessage())
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->text('Test');
        $message->getHeaders()->addTextHeader('X-EMAIL-ID', '42');

        $messageProperty = $reflection->getProperty('message');
        $messageProperty->setValue($transport, $message);

        $payload = [];
        $method = $reflection->getMethod('addSesHeaders');
        $method->invokeArgs($transport, [&$payload, &$message, []]);

        $this->assertContains(
            ['Name' => 'X-EMAIL-ID', 'Value' => '42'],
            $payload['EmailTags']
        );
    }

    public function testAcquireTokensThrowsTransportExceptionWhenBucketFileCannotBeOpened(): void
    {
        $reflection = new \ReflectionClass(AmazonSesTransport::class);
        $transport = $reflection->newInstanceWithoutConstructor();
        $loggerProperty = $reflection->getProperty('logger');
        $loggerProperty->setAccessible(true);
        $loggerProperty->setValue($transport, new NullLogger());

        $method = new \ReflectionMethod(AmazonSesTransport::class, 'acquireTokens');
        $method->setAccessible(true);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Unable to open SES rate limit token bucket file');

        $method->invoke($transport, __DIR__, 1, 1);
    }

    public function testIdleBucketDoesNotAllowAnInitialBurst(): void
    {
        $bucket = $this->createBucket(20.0, microtime(true) - 60.0);

        try {
            $started = hrtime(true);
            $this->acquireTokens($bucket, 2, 20);

            $this->assertGreaterThanOrEqual(0.09, (hrtime(true) - $started) / 1_000_000_000);
        } finally {
            unlink($bucket);
        }
    }

    public function testExistingReservationsDelayTheNextSubmission(): void
    {
        $started = hrtime(true);
        $bucket = $this->createBucket(-2.0, microtime(true));

        try {
            $this->acquireTokens($bucket, 2, 20);

            $this->assertGreaterThanOrEqual(0.18, (hrtime(true) - $started) / 1_000_000_000);
        } finally {
            unlink($bucket);
        }
    }

    public function testConcurrentWorkersShareTheConfiguredRate(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('stream_socket_pair')) {
            $this->markTestSkipped('Process control and socket pairs are needed to exercise concurrent workers.');
        }

        $bucket = $this->createBucket(20.0, microtime(true) - 60.0);
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($sockets);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);

        if (0 === $pid) {
            fclose($sockets[0]);
            // Both workers start from the same idle bucket after the parent releases this barrier.
            fread($sockets[1], 1);
            $this->acquireTokens($bucket, 2, 20);
            fwrite($sockets[1], (string) hrtime(true));
            fclose($sockets[1]);
            exit(0);
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 5);

        try {
            $started = hrtime(true);
            fwrite($sockets[0], '1');
            $this->acquireTokens($bucket, 2, 20);
            $parentFinished = hrtime(true);
            $childFinished = stream_get_contents($sockets[0]);
            pcntl_waitpid($pid, $status);

            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertNotSame('', $childFinished);
            $firstFinished = min($parentFinished, (int) $childFinished);
            $lastFinished = max($parentFinished, (int) $childFinished);
            $this->assertGreaterThanOrEqual(0.09, ($firstFinished - $started) / 1_000_000_000);
            $this->assertGreaterThanOrEqual(0.18, ($lastFinished - $started) / 1_000_000_000);
        } finally {
            fclose($sockets[0]);
            unlink($bucket);
        }
    }

    private function createBucket(float $tokens, float $lastTime): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ses-rate-test-');
        file_put_contents($path, json_encode(['tokens' => $tokens, 'last_time' => $lastTime]));

        return $path;
    }

    private function acquireTokens(string $bucket, int $tokens, int $rate): void
    {
        $reflection = new \ReflectionClass(AmazonSesTransport::class);
        $transport = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('logger')->setValue($transport, new NullLogger());
        $reflection->getMethod('acquireTokens')->invoke($transport, $bucket, $tokens, $rate);
    }
}
