<?php

declare(strict_types=1);

namespace Mautic\IntegrationsBundle\Event;

use Mautic\PluginBundle\Entity\Integration;

final class KeysSaveEvent
{
    /**
     * @param array<string, mixed> $oldKeys
     */
    public function __construct(
        private Integration $integrationConfiguration,
        private array $oldKeys,
    ) {
    }

    public function getIntegrationConfiguration(): Integration
    {
        return $this->integrationConfiguration;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOldKeys(): array
    {
        return $this->oldKeys;
    }
}
