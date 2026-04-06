<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;

/**
 * Reads durable outbound buffer toggle from published integration feature settings.
 * When the integration is not published, durable persistence is off.
 * When published but the flag is missing, durable persistence defaults to on.
 */
final class PersistentBufferFeatureReader
{
    public function __construct(
        private InsightsPublishedIntegrationFinder $integrationFinder,
    ) {
    }

    /**
     * When true, {@see ChainPersistentEventBuffer} may persist; when false, behave like {@see DisabledPersistentEventBuffer}.
     */
    public function isDurableBufferEnabled(): bool
    {
        $integration = $this->integrationFinder->findPublished();
        if (null === $integration) {
            return false;
        }

        $featureSettings = $integration->getFeatureSettings();
        if (!\is_array($featureSettings)) {
            return true;
        }

        $integrationBlock = $featureSettings[InsightsApiConfig::FEATURE_SETTINGS_INTEGRATION_SUBKEY] ?? null;
        if (!\is_array($integrationBlock)) {
            return true;
        }

        $raw = $integrationBlock[InsightsApiConfig::FEATURE_PERSISTENT_EVENT_BUFFER] ?? null;
        if (null === $raw) {
            return true;
        }

        return 1 === (int) $raw;
    }
}
