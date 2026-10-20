<?php
/*
 * @copyright       (c) 2024. e-tailors IP B.V. All rights reserved
 * @author          Paul Maas <p.maas@e-tailors.com>
 *
 * @link            https://www.e-tailors.com
 */

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Helper;

use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Helper\DTO\AddressDTO;
use Mautic\EmailBundle\Helper\FromEmailHelper;

class TokenSafeFromEmailHelper extends FromEmailHelper
{
    public function getFrom(?Email $email): AddressDTO
    {
        $from = parent::getFrom($email);

        if (!$from->isEmailTokenized()) {
            return $from;
        }

        return parent::getFrom(null);
    }
}