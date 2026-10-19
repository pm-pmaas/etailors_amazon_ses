<?php
/*
 * @copyright       (c) 2024. e-tailors IP B.V. All rights reserved
 * @author          Paul Maas <p.maas@e-tailors.com>
 *
 * @link            https://www.e-tailors.com
 */

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * @extends AbstractType<mixed>
 */
class ConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(
            'amazon_ses_sns_topic_arns',
            TextareaType::class,
            [
                'label'      => 'mautic.amazonses.config.sns_topic_arns',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'       => 'form-control',
                    'tooltip'     => 'mautic.amazonses.config.sns_topic_arns.tooltip',
                    'placeholder' => 'arn:aws:sns:eu-west-1:123456789012:mautic-ses',
                ],
                'required'   => false,
            ]
        );
    }

    public function getBlockPrefix(): string
    {
        return 'amazonsesconfig';
    }
}