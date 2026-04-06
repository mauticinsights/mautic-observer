<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

/**
 * Delegates to {@see ChainPersistentEventBuffer} or {@see DisabledPersistentEventBuffer} based on integration feature flag.
 */
final class ConfigurablePersistentEventBuffer implements PersistentEventBufferInterface
{
    public function __construct(
        private PersistentBufferFeatureReader $featureReader,
        private ChainPersistentEventBuffer $chain,
        private DisabledPersistentEventBuffer $disabled,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->delegate()->isEnabled();
    }

    /**
     * @param list<array<string, mixed>> $payloads
     */
    public function append(array $payloads): void
    {
        $this->delegate()->append($payloads);
    }

    public function loadAndLock(): EventBufferLoadResult
    {
        return $this->delegate()->loadAndLock();
    }

    public function clear(): void
    {
        $this->delegate()->clear();
    }

    private function delegate(): PersistentEventBufferInterface
    {
        return $this->featureReader->isDurableBufferEnabled() ? $this->chain : $this->disabled;
    }
}
