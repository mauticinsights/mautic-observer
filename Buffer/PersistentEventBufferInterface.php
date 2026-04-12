<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Buffer;

/**
 * Durable buffer for outbound API event payloads (file, Redis, DB, …).
 */
interface PersistentEventBufferInterface
{
    public function isEnabled(): bool;

    /**
     * @param list<array<string, mixed>> $payloads
     */
    public function append(array $payloads): void;

    public function loadAndLock(): EventBufferLoadResult;

    public function clear(): void;
}
