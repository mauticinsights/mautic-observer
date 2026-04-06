<?php

declare(strict_types=1);

namespace Mautic\PluginBundle\Entity;

use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;

/**
 * Minimal stub for {@see \MauticPlugin\MauticInsightsBundle\Service\MauticEventBuffer} tests (no mautic/core-lib in this repo).
 */
final class IntegrationRepository
{
    public function __construct(
        private ?Integration $integration,
    ) {
    }

    public function findOneByName(string $name): ?Integration
    {
        return InsightsApiConfig::NAME === $name ? $this->integration : null;
    }
}
