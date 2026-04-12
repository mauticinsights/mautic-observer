<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Buffer;

/**
 * Tries {@see FileEventBuffer}, then {@see RedisEventBuffer}, then {@see DatabaseEventBuffer}.
 */
final class ChainPersistentEventBuffer implements PersistentEventBufferInterface
{
    public function __construct(
        private FileEventBuffer $fileEventBuffer,
        private RedisEventBuffer $redisEventBuffer,
        private DatabaseEventBuffer $databaseEventBuffer,
    ) {
    }

    public function isEnabled(): bool
    {
        return null !== $this->active();
    }

    /**
     * @param list<array<string, mixed>> $payloads
     */
    public function append(array $payloads): void
    {
        $this->active()?->append($payloads);
    }

    public function loadAndLock(): EventBufferLoadResult
    {
        return $this->active()?->loadAndLock() ?? new EventBufferLoadResult([], false);
    }

    public function clear(): void
    {
        $this->active()?->clear();
    }

    /**
     * Which durable backend is active when {@see self::isEnabled()} is true; null if none.
     */
    public function getActiveBackendId(): ?string
    {
        $a = $this->active();
        if (null === $a) {
            return null;
        }

        return match (true) {
            $a === $this->fileEventBuffer => 'file',
            $a === $this->redisEventBuffer => 'redis',
            $a === $this->databaseEventBuffer => 'database',
            default => null,
        };
    }

    private function active(): FileEventBuffer|RedisEventBuffer|DatabaseEventBuffer|null
    {
        return match (true) {
            $this->fileEventBuffer->isEnabled() => $this->fileEventBuffer,
            $this->redisEventBuffer->isEnabled() => $this->redisEventBuffer,
            $this->databaseEventBuffer->isEnabled() => $this->databaseEventBuffer,
            default => null,
        };
    }
}
