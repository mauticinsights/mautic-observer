<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use DateTimeImmutable;
use DateTimeZone;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use Psr\Log\LoggerInterface;

/**
 * In-memory buffer with optional persistence. Flush sends POST to `$notifyUrl` with body `{ $requestPayloadRootKey: [ ... ] }` (defaults: `/api/event` + key `events`).
 *
 * If another process holds the lock, {@see PersistentEventBufferInterface::loadAndLock()} returns an empty backlog and only events from the current request are sent.
 *
 * All events accumulate in memory; {@see flush()} (called by {@see \MauticPlugin\MauticInsightsBundle\EventSubscriber\EventBufferFlushSubscriber} on kernel response/terminate) sends them in one batch.
 * When {@see PersistentEventBufferInterface::isEnabled()} is true, the persistent buffer is used for durability; when false, events are held in memory until flush and dropped on failure.
 */
final class MauticEventBuffer
{
    private const MAX_EVENTS_PER_REQUEST = 100;

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    public function __construct(
        private ResilientEventSender $resilientEventSender,
        private InsightsPublishedIntegrationFinder $integrationFinder,
        private InsightsHttpTokenResolver $httpTokenResolver,
        private PersistentEventBufferInterface $persistentBuffer,
        private int $flushSize = 50,
        private string $notifyUrl = InsightsApiConfig::EVENT_NOTIFY_URL,
        private string $requestPayloadRootKey = 'events',
        private ?LoggerInterface $logger = null,
        private string $httpMethod = 'POST',
    ) {
    }

    /**
     * @param array<string, mixed> $data optional extra JSON keys (`contact_id`, `segment_ids`, `channel`, `properties`, …)
     */
    public function push(string $event, array $data = []): void
    {
        $row = \array_merge(
            [
                'event' => $event,
                'ts' => $this->formatTimestamp(),
            ],
            $data
        );

        $this->buffer[] = $row;

        if (\count($this->buffer) >= $this->flushSize) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        $memory = $this->buffer;
        $this->buffer = [];

        if ([] === $memory) {
            return;
        }

        $token = $this->getNotifyToken();
        if (null === $token) {
            if ($this->persistentBuffer->isEnabled()) {
                $this->persistentBuffer->append($memory);
            } else {
                $this->logger?->critical('MauticInsights: events dropped — no token (integration not published or API key missing). Durable buffer is off.', ['count' => \count($memory), 'url' => $this->notifyUrl]);
            }

            return;
        }

        if (!$this->persistentBuffer->isEnabled()) {
            $this->sendEventBatches($memory, $token);

            return;
        }

        $result = $this->persistentBuffer->loadAndLock();

        $toSend = match ($result->lockHeld) {
            true => array_merge($result->events, $memory),
            false => $memory,
        };

        $failed = $this->sendEventBatches($toSend, $token);

        if ($result->lockHeld) {
            $this->persistentBuffer->clear();
        }

        if ([] !== $failed) {
            $this->persistentBuffer->append($failed);
        }
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return list<array<string, mixed>> events that failed to send (whole chunks on HTTP failure)
     */
    private function sendEventBatches(array $events, string $token): array
    {
        if ([] === $events) {
            return [];
        }

        $failed = [];

        foreach (array_chunk($events, self::MAX_EVENTS_PER_REQUEST) as $chunk) {
            $payload = [$this->requestPayloadRootKey => $chunk];
            if (!$this->resilientEventSender->send($this->notifyUrl, $token, $payload, $this->httpMethod)) {
                if (!$this->persistentBuffer->isEnabled()) {
                    $this->logger?->critical('MauticInsights: API request failed and durable buffer is off — events dropped permanently.', ['count' => \count($chunk), 'url' => $this->notifyUrl]);
                }
                array_push($failed, ...$chunk);
            }
        }

        return $failed;
    }

    private function getNotifyToken(): ?string
    {
        $integration = $this->integrationFinder->findPublished();
        if (null === $integration) {
            $this->logger?->debug('MauticInsights: integration not found or not published — no token.', ['url' => $this->notifyUrl]);

            return null;
        }

        $token = $this->httpTokenResolver->resolve($integration);

        if ('' === $token) {
            $this->logger?->warning('MauticInsights: integration is published but API key (http_token) is empty.', ['url' => $this->notifyUrl]);

            return null;
        }

        return $token;
    }

    private function formatTimestamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(\DATE_ATOM);
    }
}
