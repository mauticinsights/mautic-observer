<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Form\Type;

use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Integration\MauticInsightsIntegration;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Maps to {@see Integration::getApiKeys()} (credentials / first tab in the plugin config dialog).
 */
class AuthConfigType extends AbstractType
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $linkOpen = sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">',
            htmlspecialchars(InsightsApiConfig::APP_INSTANCES_SETTINGS_URL, ENT_QUOTES, 'UTF-8')
        );

        $builder->add(
            MauticInsightsIntegration::HTTP_TOKEN_FIELD,
            TextType::class,
            [
                'label' => $this->translator->trans('mautic.mauticinsights.config.http_token', [], 'messages'),
                'help' => $this->translator->trans('mautic.mauticinsights.config.http_token.help', [
                    '%link_open%' => $linkOpen,
                    '%link_close%' => '</a>',
                ], 'messages'),
                'help_html' => true,
                'label_attr' => ['class' => 'control-label'],
                'attr' => [
                    'class' => 'form-control',
                    'autocomplete' => 'off',
                    // Skip |purify on help so target="_blank" is kept (Core layout strips it via HTMLPurifier).
                    'data-mauticinsights-raw-help' => '1',
                ],
                'required' => false,
                'translation_domain' => false,
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
