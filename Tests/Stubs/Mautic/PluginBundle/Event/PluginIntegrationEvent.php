<?php

declare(strict_types=1);

namespace Mautic\PluginBundle\Event;

use Mautic\PluginBundle\Entity\Integration;

/**
 * Test double: production event wraps {@see \Mautic\PluginBundle\Integration\UnifiedIntegrationInterface}; tests only need {@see getEntity()}.
 */
final class PluginIntegrationEvent
{
    public function __construct(
        private Integration $integration,
    ) {
    }

    public function getEntity(): Integration
    {
        return $this->integration;
    }
}
