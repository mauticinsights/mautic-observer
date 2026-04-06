<?php

declare(strict_types=1);

namespace Mautic\IntegrationsBundle\Event;

use Mautic\PluginBundle\Entity\Integration;

final class ConfigSaveEvent
{
    public function __construct(
        private Integration $integration,
    ) {
    }

    public function getIntegrationConfiguration(): Integration
    {
        return $this->integration;
    }
}
