<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Buffer;

use DateTimeImmutable;
use DateTimeZone;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Service\InsightsApiSendGate;
use MauticPlugin\MauticInsightsBundle\Service\InsightsHttpTokenResolver;
use MauticPlugin\MauticInsightsBundle\Service\InsightsPublishedIntegrationFinder;
use MauticPlugin\MauticInsightsBundle\Service\ResilientEventSender;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * In-memory buffer with optional persistence. Flush sends POST to `$notifyUrl` with body `{ $requestPayloadRootKey: [ ... ] }` (defaults: {@see InsightsApiConfig::EVENT_NOTIFY_URL} + key `events`).
 *
 * If another process holds the lock, {@see PersistentEventBufferInterface::loadAndLock()} returns an empty backlog and only events from the current request are sent.
 *
 * All events accumulate in memory; {@see flush()} (called by {@see \MauticPlugin\MauticInsightsBundle\EventSubscriber\EventBufferFlushSubscriber} on kernel response/terminate) sends them in one batch.
 * When {@see PersistentEventBufferInterface::isEnabled()} is true, the persistent buffer is used for durability; when false, events are held in memory until flush and dropped on failure.
 *
 * Before HTTP send, events are sorted by payload {@code ts} (ISO-8601), oldest first; rows without a parseable {@code ts} go last, preserving their relative order.
 *
 * {@see InsightsApiSendGate} applies exponential backoff across flushes and (after 24h outage) can unpublish the integration.
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
        private InsightsApiSendGate $sendGate,
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

        if ($this->sendGate->shouldSkipHttpThisFlush()) {
            if ($this->persistentBuffer->isEnabled()) {
                $this->persistentBuffer->append($memory);
            } else {
                $this->buffer = array_merge($memory, $this->buffer);
            }

            return;
        }

        if (!$this->persistentBuffer->isEnabled()) {
            $failed = $this->sendEventBatches($memory, $token);
            $this->sendGate->afterFlush(true, [] === $failed);

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

        $this->sendGate->afterFlush(true, [] === $failed);
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

        $events = $this->sortEventsByTimestampOldestFirst($events);

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

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return list<array<string, mixed>>
     */
    private function sortEventsByTimestampOldestFirst(array $events): array
    {
        if (\count($events) < 2) {
            return $events;
        }

        $decorated = [];
        foreach ($events as $i => $e) {
            $decorated[] = [
                'i' => $i,
                'k' => $this->eventTimestampSortKey($e),
                'e' => $e,
            ];
        }

        usort(
            $decorated,
            static function (array $a, array $b): int {
                $c = $a['k'] <=> $b['k'];

                return 0 !== $c ? $c : $a['i'] <=> $b['i'];
            }
        );

        $out = [];
        foreach ($decorated as $row) {
            $out[] = $row['e'];
        }

        return $out;
    }

    /**
     * Unix timestamp for sorting; {@see \PHP_INT_MAX} means missing/invalid {@code ts} (sorted last).
     *
     * @param array<string, mixed> $event
     */
    private function eventTimestampSortKey(array $event): int
    {
        $ts = $event['ts'] ?? null;
        if (!\is_string($ts) || '' === trim($ts)) {
            return \PHP_INT_MAX;
        }

        try {
            return (new DateTimeImmutable($ts))->getTimestamp();
        } catch (Throwable) {
            return \PHP_INT_MAX;
        }
    }
}
