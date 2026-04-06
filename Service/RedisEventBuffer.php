<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * FIFO queue in Redis with a short-lived drain lock (SET NX). Used when the file buffer directory is not writable.
 * Pass phpredis {@see \Redis} or a compatible client (ping, rPush, lRange, del, set).
 */
final class RedisEventBuffer
{
    private bool $enabled;

    public function __construct(
        private ?object $redis,
        private LoggerInterface $logger,
        private string $queueKey = 'mautic_insights:api_events:queue',
        private string $lockKey = 'mautic_insights:api_events:drain_lock',
        private int $lockTtlSeconds = 120,
    ) {
        $this->enabled = $this->probeRedis();
        if (!$this->enabled && null !== $this->redis) {
            $this->logger->notice('Mautic Insights: Redis event buffer disabled (unreachable or ext-redis not usable).');
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @param list<array<string, mixed>> $payloads
     */
    public function append(array $payloads): void
    {
        if (!$this->enabled || [] === $payloads) {
            return;
        }

        try {
            foreach ($payloads as $payload) {
                $this->redis->rPush($this->queueKey, json_encode($payload, JSON_THROW_ON_ERROR));
            }
        } catch (JsonException $e) {
            $this->logger->warning('Mautic Insights: Redis event buffer JSON encode failed: '.$e->getMessage(), ['exception' => $e]);
        } catch (Throwable $e) {
            $this->logger->warning('Mautic Insights: Redis event buffer append failed: '.$e->getMessage(), ['exception' => $e]);
        }
    }

    public function loadAndLock(): EventBufferLoadResult
    {
        if (!$this->enabled) {
            return new EventBufferLoadResult([], false);
        }

        try {
            $locked = (bool) $this->redis->set($this->lockKey, '1', ['nx', 'ex' => $this->lockTtlSeconds]);
            if (!$locked) {
                return new EventBufferLoadResult([], false);
            }

            $raw = $this->redis->lRange($this->queueKey, 0, -1);
            if (!\is_array($raw) || [] === $raw) {
                $this->releaseLock();

                return new EventBufferLoadResult([], false);
            }

            return new EventBufferLoadResult($this->decodeLines($raw), true);
        } catch (Throwable $e) {
            $this->logger->warning('Mautic Insights: Redis event buffer loadAndLock failed: '.$e->getMessage(), ['exception' => $e]);

            return new EventBufferLoadResult([], false);
        }
    }

    public function clear(): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $this->redis->del($this->queueKey);
        } catch (Throwable $e) {
            $this->logger->warning('Mautic Insights: Redis event buffer clear queue failed: '.$e->getMessage(), ['exception' => $e]);
        }

        $this->releaseLock();
    }

    private function probeRedis(): bool
    {
        if (null === $this->redis) {
            return false;
        }

        foreach (['ping', 'rPush', 'lRange', 'del', 'set'] as $method) {
            if (!method_exists($this->redis, $method)) {
                return false;
            }
        }

        try {
            return match ($this->redis->ping()) {
                true, 'PONG', '+PONG' => true,
                default => false,
            };
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param list<mixed> $lines
     *
     * @return list<array<string, mixed>>
     */
    private function decodeLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (!\is_string($line) || '' === trim($line)) {
                continue;
            }
            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (\is_array($decoded)) {
                    $out[] = $decoded;
                }
            } catch (JsonException $e) {
                $this->logger->warning('Mautic Insights: skipping invalid JSON in Redis event buffer.', ['exception' => $e]);
            }
        }

        return $out;
    }

    private function releaseLock(): void
    {
        try {
            $this->redis->del($this->lockKey);
        } catch (Throwable) {
        }
    }
}
