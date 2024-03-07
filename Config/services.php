<?php
/*
 * @copyright       (c) 2024. e-tailors IP B.V. All rights reserverd
 * @author          Paul Maas <p.maas@e-tailors.com>
 *
 * @link            https://www.e-tailors.com
 */

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use MauticPlugin\AmazonSesBundle\Mailer\Factory\AmazonSesTransportFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
/**
 * @param ContainerConfigurator $configurator
 * @return void
 */
return static function (ContainerConfigurator $configurator) {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $excludes = [
        'Mailer/Transport/AmazonSesTransport.php',
    ];

    $services->load('MauticPlugin\\AmazonSesBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, $excludes)).'}');

    $services->get(AmazonSesTransportFactory::class)->tag('mailer.transport_factory');
};
