<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Mailer\Bulk;

/** An eligibility decision made before any request is submitted. */
final class IneligibleMessage extends \RuntimeException
{
}
