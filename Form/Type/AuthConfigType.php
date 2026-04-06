<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Form\Type;

use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticInsightsBundle\Integration\MauticInsightsIntegration;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Maps to {@see Integration::getApiKeys()} (credentials / first tab in the plugin config dialog).
 */
class AuthConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(
            MauticInsightsIntegration::HTTP_TOKEN_FIELD,
            TextType::class,
            [
                'label' => 'mautic.mauticinsights.config.http_token',
                'help' => 'mautic.mauticinsights.config.http_token.help',
                'label_attr' => ['class' => 'control-label'],
                'attr' => [
                    'class' => 'form-control',
                    'autocomplete' => 'off',
                ],
                'required' => false,
                'translation_domain' => 'messages',
                'constraints' => [
                    new Length(max: 4096),
                ],
            ]
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Passed by IntegrationsBundle\IntegrationConfigType when embedding as apiKeys (may be unused here).
        $resolver->setDefaults([
            'data_class' => null,
            'integration' => null,
        ]);
        $resolver->setAllowedTypes('integration', ['null', 'object']);
    }
}
