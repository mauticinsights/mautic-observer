<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

/**
 * Outcome of {@see PersistentEventBufferInterface::loadAndLock()}.
 */
final class EventBufferLoadResult
{
    /**
     * @param list<array<string, mixed>> $events
     */
    public function __construct(
        public array $events,
        public bool $lockHeld,
    ) {
    }
}
