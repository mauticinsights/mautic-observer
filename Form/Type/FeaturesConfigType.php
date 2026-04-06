<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Maps to {@see \Mautic\PluginBundle\Entity\Integration::getFeatureSettings()} under key {@see InsightsApiConfig::FEATURE_SETTINGS_INTEGRATION_SUBKEY}.
 */
final class FeaturesConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $data = $event->getData();
            if (!\is_array($data)) {
                $data = [];
            }
            if (!\array_key_exists(InsightsApiConfig::FEATURE_PERSISTENT_EVENT_BUFFER, $data)) {
                $data[InsightsApiConfig::FEATURE_PERSISTENT_EVENT_BUFFER] = 0;
            }
            if (!\array_key_exists(InsightsApiConfig::FEATURE_PLUGIN_LOGGING, $data)) {
                $data[InsightsApiConfig::FEATURE_PLUGIN_LOGGING] = 0;
            }
            $event->setData($data);
        });

        $builder->add(
            InsightsApiConfig::FEATURE_PERSISTENT_EVENT_BUFFER,
            YesNoButtonGroupType::class,
            [
                'label' => 'mautic.mauticinsights.features.persistent_buffer',
                'help' => 'mautic.mauticinsights.features.persistent_buffer.help',
                'translation_domain' => 'messages',
                'label_attr' => ['class' => 'control-label'],
            ]
        );

        $builder->add(
            InsightsApiConfig::FEATURE_PLUGIN_LOGGING,
            YesNoButtonGroupType::class,
            [
                'label' => 'mautic.mauticinsights.features.plugin_logging',
                'help' => 'mautic.mauticinsights.features.plugin_logging.help',
                'translation_domain' => 'messages',
                'label_attr' => ['class' => 'control-label'],
            ]
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
