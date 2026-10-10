<?php

declare(strict_types=1);

namespace Mautic\IntegrationsBundle\Integration\Interfaces;

use Mautic\PluginBundle\Entity\Integration;

/**
 * Standalone-test stub matching Mautic {@see IntegrationInterface}.
 */
interface IntegrationInterface
{
    public function getName(): string;

    public function getDisplayName(): string;

    public function hasIntegrationConfiguration(): bool;

    public function getIntegrationConfiguration(): Integration;

    public function setIntegrationConfiguration(Integration $integration);
}
