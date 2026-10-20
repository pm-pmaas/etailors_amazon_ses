<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Tests\Unit\EventSubscriber;

use Mautic\ConfigBundle\ConfigEvents;
use Mautic\ConfigBundle\Event\ConfigBuilderEvent;
use Mautic\CoreBundle\Helper\BundleHelper;
use MauticPlugin\AmazonSesBundle\EventSubscriber\ConfigSubscriber;
use MauticPlugin\AmazonSesBundle\Form\Type\ConfigType;
use PHPUnit\Framework\TestCase;

class ConfigSubscriberTest extends TestCase
{
    public function testSubscribesToConfigGenerateEvent(): void
    {
        $this->assertSame(
            [ConfigEvents::CONFIG_ON_GENERATE => ['onConfigGenerate', 0]],
            ConfigSubscriber::getSubscribedEvents()
        );
    }

    public function testAddsAmazonSesConfigurationFormFromPluginConfig(): void
    {
        $event = new ConfigBuilderEvent($this->createBundleHelper());

        (new ConfigSubscriber())->onConfigGenerate($event);

        $forms = $event->getForms();
        $this->assertArrayHasKey('amazonsesconfig', $forms);
        $this->assertSame('AmazonSesBundle', $forms['amazonsesconfig']['bundle']);
        $this->assertSame(ConfigType::class, $forms['amazonsesconfig']['formType']);
        $this->assertSame('@AmazonSes/FormTheme/Config/_config_amazonsesconfig_widget.html.twig', $forms['amazonsesconfig']['formTheme']);
        $this->assertSame(
            ['amazon_ses_sns_topic_arns' => 'arn:aws:sns:eu-west-1:123456789012:mautic-ses'],
            $forms['amazonsesconfig']['parameters']
        );
    }

    private function createBundleHelper(): BundleHelper
    {
        return new BundleHelper(
            [],
            [
                'AmazonSesBundle' => [
                    'config' => [
                        'parameters' => [
                            'amazon_ses_sns_topic_arns' => 'arn:aws:sns:eu-west-1:123456789012:mautic-ses',
                        ],
                    ],
                ],
            ]
        );
    }
}