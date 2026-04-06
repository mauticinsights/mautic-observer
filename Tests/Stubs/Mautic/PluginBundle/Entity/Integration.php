<?php

declare(strict_types=1);

namespace Mautic\PluginBundle\Entity;

use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;

/**
 * Minimal stub for {@see \MauticPlugin\MauticInsightsBundle\Service\MauticEventBuffer} tests (no mautic/core-lib in this repo).
 */
final class Integration
{
    /**
     * @param array<string, mixed> $featureSettings
     * @param array<string, mixed> $apiKeys
     */
    public function __construct(
        private bool $published,
        private array $featureSettings = [],
        private string $name = InsightsApiConfig::NAME,
        private array $apiKeys = [],
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getIsPublished(): bool
    {
        return $this->published;
    }

    public function makePublished(): void
    {
        $this->published = true;
    }

    /**
     * @return array<string, mixed>
     */
    public function getFeatureSettings(): array
    {
        return $this->featureSettings;
    }

    /**
     * @return array<string, mixed>
     */
    public function getApiKeys(): array
    {
        return $this->apiKeys;
    }
}
