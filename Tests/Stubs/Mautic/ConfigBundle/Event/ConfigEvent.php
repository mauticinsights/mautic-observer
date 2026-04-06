<?php

declare(strict_types=1);

namespace Mautic\ConfigBundle\Event;

final class ConfigEvent
{
    /**
     * @param array<string, mixed> $configs
     */
    public function __construct(
        private array $configs = [],
    ) {
    }

    public function getConfig(string $key): mixed
    {
        return $this->configs[$key] ?? [];
    }
}
