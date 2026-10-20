<?php
/*
 * @copyright       (c) 2024. e-tailors IP B.V. All rights reserved
 * @author          Paul Maas <p.maas@e-tailors.com>
 *
 * @link            https://www.e-tailors.com
 */

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Tests\Unit\Helper;

use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Helper\DTO\AddressDTO;
use MauticPlugin\AmazonSesBundle\Helper\TokenSafeFromEmailHelper;
use PHPUnit\Framework\TestCase;

class TokenSafeFromEmailHelperTest extends TestCase
{
    public function testGetFromFallsBackToDefaultForTokenizedFromAddress(): void
    {
        $helper = $this->createHelper();
        $helper->setDefaultFrom(new AddressDTO('default@example.com', 'Default Sender'));
        $email = (new Email())
            ->setFromAddress('{contactfield=sender}')
            ->setFromName('{contactfield=sender_name}');

        $from = $helper->getFrom($email);

        $this->assertSame('default@example.com', $from->getEmail());
        $this->assertSame('Default Sender', $from->getName());
        $this->assertSame('default@example.com', $from->toMailerAddress()->getAddress());
    }

    public function testGetFromKeepsFixedFromAddress(): void
    {
        $helper = $this->createHelper();
        $helper->setDefaultFrom(new AddressDTO('default@example.com', 'Default Sender'));
        $email = (new Email())
            ->setFromAddress('fixed@example.com')
            ->setFromName('Fixed Sender');

        $from = $helper->getFrom($email);

        $this->assertSame('fixed@example.com', $from->getEmail());
        $this->assertSame('Fixed Sender', $from->getName());
    }

    private function createHelper(): TokenSafeFromEmailHelper
    {
        $reflection = new \ReflectionClass(TokenSafeFromEmailHelper::class);

        return $reflection->newInstanceWithoutConstructor();
    }
}