<?php
/*
 * @copyright       (c) 2024. e-tailors IP B.V. All rights reserved
 * @author          Paul Maas <p.maas@e-tailors.com>
 *
 * @link            https://www.e-tailors.com
 */

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use Mautic\EmailBundle\Helper\FromEmailHelper;
use MauticPlugin\AmazonSesBundle\EventSubscriber\CallbackSubscriber;
use MauticPlugin\AmazonSesBundle\Helper\SnsWebhookAuthenticator;
use MauticPlugin\AmazonSesBundle\Helper\TokenSafeFromEmailHelper;
use MauticPlugin\AmazonSesBundle\Mailer\Factory\AmazonSesTransportFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service; 

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

    $services->set(FromEmailHelper::class, TokenSafeFromEmailHelper::class)
        ->public();

    $services->get(CallbackSubscriber::class)
        ->arg('$translator', service('translator'))
        ->arg('$logger', service('logger'))
        ->arg('$snsWebhookAuthenticator', service(SnsWebhookAuthenticator::class));

    $services->get(\MauticPlugin\AmazonSesBundle\Mailer\Factory\AmazonSesTransportFactory::class)
        ->arg('$transportCallback', service(\Mautic\EmailBundle\Model\TransportCallback::class))
        ->arg('$eventDispatcher', service('event_dispatcher'))
        ->arg('$translator', service('translator'))
        ->arg('$entityManager', service('doctrine.orm.entity_manager'))
        ->arg('$pathsHelper', service('mautic.helper.paths'))
        ->arg('$logger', service('logger'))
        ->tag('mailer.transport_factory');
};
