<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Buffer;

use DateTimeImmutable;
use DateTimeZone;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Service\InsightsApiSendGate;
use MauticPlugin\MauticInsightsBundle\Service\InsightsOutboundTokenProvider;
use MauticPlugin\MauticInsightsBundle\Service\ResilientEventSender;
use MauticPlugin\MauticInsightsBundle\Service\SyncSessionManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * In-memory buffer with optional persistence. Flush sends POST to `$notifyUrl` with body `{ $requestPayloadRootKey: [ ... ] }` (defaults: {@see InsightsApiConfig::EVENT_NOTIFY_URL} + key `events`).
 *
 * If another process holds the lock, {@see PersistentEventBufferInterface::loadAndLock()} returns an empty backlog and only events from the current request are sent.
 *
 * When the integration is not published (or the API key is empty), {@see flush()} clears the durable buffer and
 * does not write new rows; {@see push()} is a no-op. Subscribers are expected to return early in that state so
 * payloads are not built.
 *
 * All events accumulate in memory; {@see flush()} (called by {@see \MauticPlugin\MauticInsightsBundle\EventSubscriber\EventBufferFlushSubscriber} on kernel response/terminate) sends them in one batch.
 * When {@see PersistentEventBufferInterface::isEnabled()} is true, the persistent buffer is used for durability; when false, events are held in memory until flush and dropped on failure.
 *
 * Before HTTP send, events are sorted by payload {@code ts} (ISO-8601, including fractional seconds), oldest first;
 * rows without a parseable {@code ts} go last, preserving their relative order. Auto-generated {@code ts} uses
 * microsecond resolution so same-request pushes keep enqueue order when wall-clock second is identical.
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
        private InsightsOutboundTokenProvider $outboundTokenProvider,
        private PersistentEventBufferInterface $persistentBuffer,
        private InsightsApiSendGate $sendGate,
        private int $flushSize = 50,
        private string $notifyUrl = InsightsApiConfig::EVENT_NOTIFY_URL,
        private string $requestPayloadRootKey = 'events',
        private ?LoggerInterface $logger = null,
        private string $httpMethod = 'POST',
        private ?SyncSessionManager $syncSessionManager = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data optional extra JSON keys (`contact_id`, `segment_ids`, `channel`, `properties`, …)
     */
    public function push(string $event, array $data = []): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

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

        $token = $this->outboundTokenProvider->getToken();
        if (null === $token) {
            $this->persistentBuffer->clear();
            if ([] === $memory) {
                return;
            }
            $this->logger?->debug('MauticInsights: outbound inactive — in-memory events discarded, durable store cleared.', [
                'count' => \count($memory),
                'url' => $this->notifyUrl,
            ]);

            return;
        }

        if ([] === $memory) {
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

        $syncId = $this->syncSessionManager?->getSyncId();

        foreach (array_chunk($events, self::MAX_EVENTS_PER_REQUEST) as $chunk) {
            $payload = [$this->requestPayloadRootKey => $chunk];
            if (null !== $syncId && '' !== $syncId) {
                $payload['sync_id'] = $syncId;
            }
            if (!$this->resilientEventSender->send($this->notifyUrl, $token, $payload, $this->httpMethod)) {
                if (!$this->persistentBuffer->isEnabled()) {
                    $this->logger?->critical('MauticInsights: API request failed and durable buffer is off — events dropped permanently.', ['count' => \count($chunk), 'url' => $this->notifyUrl]);
                }
                array_push($failed, ...$chunk);
            }
        }

        return $failed;
    }

    private function formatTimestamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
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
     * Sort key: Unix time as float (seconds + fractional part from {@code ts}). {@see INF} = missing/invalid {@code ts} (last).
     *
     * @param array<string, mixed> $event
     */
    private function eventTimestampSortKey(array $event): float
    {
        $ts = $event['ts'] ?? null;
        if (!\is_string($ts) || '' === trim($ts)) {
            return \INF;
        }

        try {
            $dt = new DateTimeImmutable($ts);

            return (float) $dt->format('U.u');
        } catch (Throwable) {
            return \INF;
        }
    }
}
