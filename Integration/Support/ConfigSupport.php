<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Integration\Support;

use Mautic\IntegrationsBundle\Integration\DefaultConfigFormTrait;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormFeatureSettingsInterface;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormInterface;
use MauticPlugin\MauticInsightsBundle\Form\Type\FeaturesConfigType;
use MauticPlugin\MauticInsightsBundle\Integration\MauticInsightsIntegration;

class ConfigSupport extends MauticInsightsIntegration implements ConfigFormInterface, ConfigFormFeatureSettingsInterface
{
    use DefaultConfigFormTrait;

    public function getFeatureSettingsConfigFormName(): string
    {
        return FeaturesConfigType::class;
    }

    public function getConfigFormContentTemplate(): ?string
    {
        return '@MauticInsightsBundle/Integration/Config/form.html.twig';
    }
}
