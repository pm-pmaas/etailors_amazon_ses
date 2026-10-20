<?php
/*
 * @copyright       (c) 2024. e-tailors IP B.V. All rights reserved
 * @author          Paul Maas <p.maas@e-tailors.com>
 *
 * @link            https://www.e-tailors.com
 */

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\EventSubscriber;

use Mautic\ConfigBundle\ConfigEvents;
use Mautic\ConfigBundle\Event\ConfigBuilderEvent;
use MauticPlugin\AmazonSesBundle\Form\Type\ConfigType;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ConfigSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ConfigEvents::CONFIG_ON_GENERATE => ['onConfigGenerate', 0],
        ];
    }

    public function onConfigGenerate(ConfigBuilderEvent $event): void
    {
        $event->addForm([
            'bundle'     => 'AmazonSesBundle',
            'formAlias'  => 'amazonsesconfig',
            'formType'   => ConfigType::class,
            'formTheme'  => '@AmazonSes/FormTheme/Config/_config_amazonsesconfig_widget.html.twig',
            'parameters' => $event->getParametersFromConfig('AmazonSesBundle'),
        ]);
    }
}