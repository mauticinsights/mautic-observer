<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Form\Type;

use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Integration\MauticInsightsIntegration;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Maps to {@see Integration::getApiKeys()} (credentials / first tab in the plugin config dialog).
 *
 * Uses a password field with a placeholder (same pattern as {@see \Mautic\PluginBundle\Form\Type\KeysType}).
 * Values are encrypted on save by IntegrationsBundle {@see \Mautic\IntegrationsBundle\Helper\IntegrationsHelper}.
 * Empty submit with an existing token is handled by
 * {@see \MauticPlugin\MauticInsightsBundle\EventSubscriber\ApiKeysBeforeSaveSubscriber}.
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

        /** @var array<string, mixed> $apiKeys */
        $apiKeys = \is_array($options['data'] ?? null) ? $options['data'] : [];
        $hasExistingToken = '' !== \trim((string) ($apiKeys[InsightsApiConfig::HTTP_TOKEN_FIELD] ?? ''));

        $builder->add(
            MauticInsightsIntegration::HTTP_TOKEN_FIELD,
            PasswordType::class,
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
                    'placeholder' => $this->translator->trans(
                        'mautic.mauticinsights.config.http_token.placeholder',
                        [],
                        'messages'
                    ),
                    'data-mauticinsights-raw-help' => '1',
                ],
                'required' => false,
                'always_empty' => true,
                'translation_domain' => false,
                'constraints' => [
                    new Callback($this->buildTokenRequiredCallback($hasExistingToken)),
                    new Length(max: 4096),
                ],
            ]
        );
    }

    /**
     * Require a token when the integration is published and none is stored yet.
     * When a token already exists, an empty password field means “keep current value”.
     */
    private function buildTokenRequiredCallback(bool $hasExistingToken): Callback
    {
        return new Callback(
            function (mixed $value, ExecutionContextInterface $context) use ($hasExistingToken): void {
                if ($hasExistingToken && '' === \trim((string) $value)) {
                    return;
                }

                $root = $context->getRoot()->getData();
                if (!$root instanceof Integration || !$root->getIsPublished()) {
                    return;
                }

                if ('' === \trim((string) $value)) {
                    $context->buildViolation('mautic.core.value.required')->addViolation();
                }
            }
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'integration' => null,
        ]);
        $resolver->setAllowedTypes('integration', ['null', 'object']);
    }
}
