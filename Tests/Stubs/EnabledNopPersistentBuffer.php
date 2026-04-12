<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\Stubs;

use MauticPlugin\MauticInsightsBundle\Buffer\EventBufferLoadResult;
use MauticPlugin\MauticInsightsBundle\Buffer\PersistentEventBufferInterface;

/**
 * Always-enabled no-op persistent buffer for unit tests: reports isEnabled()=true so
 * {@see \MauticPlugin\MauticInsightsBundle\Buffer\MauticEventBuffer} accumulates events
 * in memory and sends them in a single batch on flush() — matching test expectations.
 */
final class EnabledNopPersistentBuffer implements PersistentEventBufferInterface
{
    public function isEnabled(): bool
    {
        return true;
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
