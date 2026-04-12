<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Buffer;

/**
 * No persistence — use for a separate {@see MauticEventBuffer} (e.g. segments) so it does not share the main queue.
 */
final class DisabledPersistentEventBuffer implements PersistentEventBufferInterface
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function append(array $payloads): void
    {
    }

    public function loadAndLock(): EventBufferLoadResult
    {
        return new EventBufferLoadResult([], false);
    }

    public function clear(): void
    {
    }
}
