<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Tests\Unit\Helper;

use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use MauticPlugin\AmazonSesBundle\Helper\SnsWebhookAuthenticator;
use PHPUnit\Framework\TestCase;

final class SnsWebhookAuthenticatorTest extends TestCase
{
    private const TOPIC = 'arn:aws:sns:eu-west-1:123456789012:mautic-feedback';

    public function testAuthenticatesOnlyUntamperedMessagesFromAllowedTopic(): void
    {
        $key       = openssl_pkey_new(['private_key_bits' => 2048]);
        $publicKey = openssl_pkey_get_details($key)['key'];
        $validator = new MessageValidator(static fn (string $url): string => $publicKey);
        $payload   = $this->signedPayload($key, $validator);
        $subject   = new SnsWebhookAuthenticator();

        self::assertTrue($subject->authenticate($payload, [self::TOPIC], $validator));
        self::assertNull($subject->getLastFailureReason());

        $tampered            = $payload;
        $tampered['Message'] = '{"notificationType":"Complaint"}';
        self::assertFalse($subject->authenticate($tampered, [self::TOPIC], $validator));
        self::assertStringStartsWith('SNS signature validation failed:', (string) $subject->getLastFailureReason());

        self::assertFalse($subject->authenticate($payload, [self::TOPIC.'-other'], $validator));
        self::assertSame('SNS TopicArn is not allowed.', $subject->getLastFailureReason());

        self::assertFalse($subject->authenticate(['eventType' => 'Bounce'], [self::TOPIC], $validator));
        self::assertSame('SNS payload is missing TopicArn.', $subject->getLastFailureReason());
    }

    /** @return array<string, string> */
    private function signedPayload(\OpenSSLAsymmetricKey $key, MessageValidator $validator): array
    {
        $payload = [
            'Type'              => 'Notification',
            'Message'           => '{"notificationType":"Bounce"}',
            'MessageId'         => 'unit-test',
            'Timestamp'         => '2026-09-20T00:00:00Z',
            'TopicArn'          => self::TOPIC,
            'SignatureVersion'  => '2',
            'Signature'         => '',
            'SigningCertURL'    => 'https://sns.eu-west-1.amazonaws.com/test.pem',
        ];
        openssl_sign(
            $validator->getStringToSign(new Message($payload)),
            $signature,
            $key,
            OPENSSL_ALGO_SHA256,
        );
        $payload['Signature'] = base64_encode($signature);

        return $payload;
    }
}
