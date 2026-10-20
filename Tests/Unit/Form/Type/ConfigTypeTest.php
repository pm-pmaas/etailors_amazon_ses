<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Tests\Unit\Form\Type;

use MauticPlugin\AmazonSesBundle\Form\Type\ConfigType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

class ConfigTypeTest extends TestCase
{
    public function testAmazonSesSnsTopicArnsFieldIsAddedToPluginConfiguration(): void
    {
        $builder = new FormBuilder('amazonsesconfig', null, new EventDispatcher(), $this->createFormFactoryThatMustNotBeUsed());

        (new ConfigType())->buildForm($builder, ['data' => []]);

        $fields = $this->getUnresolvedFields($builder);
        $this->assertArrayHasKey('amazon_ses_sns_topic_arns', $fields);

        [$type, $options] = $fields['amazon_ses_sns_topic_arns'];

        $this->assertSame(TextareaType::class, $type);
        $this->assertSame('mautic.amazonses.config.sns_topic_arns', $options['label']);
        $this->assertFalse($options['required']);
        $this->assertSame('mautic.amazonses.config.sns_topic_arns.tooltip', $options['attr']['tooltip']);
    }

    public function testBlockPrefixMatchesPluginConfigAlias(): void
    {
        $this->assertSame('amazonsesconfig', (new ConfigType())->getBlockPrefix());
    }

    /**
     * @return array<string, array{0: string|null, 1: array<string, mixed>}>
     */
    private function getUnresolvedFields(FormBuilder $builder): array
    {
        $reflection = new \ReflectionObject($builder);
        $property   = $reflection->getProperty('unresolvedChildren');

        return $property->getValue($builder);
    }

    private function createFormFactoryThatMustNotBeUsed(): FormFactoryInterface
    {
        return new class() implements FormFactoryInterface {
            public function create(string $type = 'Symfony\Component\Form\Extension\Core\Type\FormType', mixed $data = null, array $options = []): FormInterface
            {
                throw new \RuntimeException('Form factory must not be used by this test.');
            }

            public function createNamed(string $name, string $type = 'Symfony\Component\Form\Extension\Core\Type\FormType', mixed $data = null, array $options = []): FormInterface
            {
                throw new \RuntimeException('Form factory must not be used by this test.');
            }

            public function createForProperty(string $class, string $property, mixed $data = null, array $options = []): FormInterface
            {
                throw new \RuntimeException('Form factory must not be used by this test.');
            }

            public function createBuilder(string $type = 'Symfony\Component\Form\Extension\Core\Type\FormType', mixed $data = null, array $options = []): FormBuilderInterface
            {
                throw new \RuntimeException('Form factory must not be used by this test.');
            }

            public function createNamedBuilder(string $name, string $type = 'Symfony\Component\Form\Extension\Core\Type\FormType', mixed $data = null, array $options = []): FormBuilderInterface
            {
                throw new \RuntimeException('Form factory must not be used by this test.');
            }

            public function createBuilderForProperty(string $class, string $property, mixed $data = null, array $options = []): FormBuilderInterface
            {
                throw new \RuntimeException('Form factory must not be used by this test.');
            }
        };
    }

}