<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\Stubs;

use MauticPlugin\MauticInsightsBundle\Buffer\EventBufferLoadResult;
use MauticPlugin\MauticInsightsBundle\Buffer\PersistentEventBufferInterface;

/**
 * Durable buffer on: {@see loadAndLock()} returns a fixed backlog and optionally simulates lock held (merge path in {@see \MauticPlugin\MauticInsightsBundle\Buffer\MauticEventBuffer::flush()}).
 */
final class SeededBacklogPersistentBuffer implements PersistentEventBufferInterface
{
    /**
     * @param list<array<string, mixed>> $backlog
     */
    public function __construct(
        private array $backlog,
        private bool $lockHeld = true,
    ) {
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function append(array $payloads): void
    {
    }

    public function loadAndLock(): EventBufferLoadResult
    {
        return new EventBufferLoadResult($this->backlog, $this->lockHeld);
    }

    public function clear(): void
    {
    }
}
