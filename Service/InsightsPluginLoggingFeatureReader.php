<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;

/**
 * Reads the “plugin logging” toggle from published integration feature settings (default off).
 */
class InsightsPluginLoggingFeatureReader
{
    public function __construct(
        private InsightsPublishedIntegrationFinder $integrationFinder,
    ) {
    }

    public function isPluginLoggingEnabled(): bool
    {
        $integration = $this->integrationFinder->findPublished();
        if (null === $integration) {
            return false;
        }

        $featureSettings = $integration->getFeatureSettings();
        if (!\is_array($featureSettings)) {
            return false;
        }

        $integrationBlock = $featureSettings[InsightsApiConfig::FEATURE_SETTINGS_INTEGRATION_SUBKEY] ?? null;
        if (!\is_array($integrationBlock)) {
            return false;
        }

        $raw = $integrationBlock[InsightsApiConfig::FEATURE_PLUGIN_LOGGING] ?? null;
        if (null === $raw) {
            return false;
        }

        return 1 === (int) $raw;
    }
}
