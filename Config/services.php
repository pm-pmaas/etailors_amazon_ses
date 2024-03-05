<?php

declare(strict_types=1);
use MauticPlugin\AmazonSesBundle\Mailer\Factory\AmazonSesTransportFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $configurator) {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('MauticPlugin\\AmazonSesBundle\\', '../')
        ->exclude('../{Config,Helper/AmazonSesResponse.php,Mailer/Transport/AmazonSesTransport.php}');

    $services->get(AmazonSesTransportFactory::class)->tag('mailer.transport_factory');
};
