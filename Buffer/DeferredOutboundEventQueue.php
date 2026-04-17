<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Buffer;

use DateTimeImmutable;
use DateTimeZone;

/**
 * FIFO queue for events that must not hit {@see MauticEventBuffer} until request shutdown flush.
 *
 * Shared singleton: {@see \MauticPlugin\MauticInsightsBundle\EventSubscriber\LeadInsightsEventSubscriber},
 * {@see \MauticPlugin\MauticInsightsBundle\EventSubscriber\EmailInsightsEventSubscriber} (opens, sends, display, reply, entity save/delete, queue fail/resend, transport webhook, point trigger mail),
 * {@see \MauticPlugin\MauticInsightsBundle\EventSubscriber\CampaignAnalyticsEventSubscriber} (campaign save/delete),
 * {@see \MauticPlugin\MauticInsightsBundle\EventSubscriber\SegmentInsightsEventSubscriber} (segment save/delete),
 * {@see \MauticPlugin\MauticInsightsBundle\EventSubscriber\PageInsightsEventSubscriber} (page hit) enqueue here;
 * {@see \MauticPlugin\MauticInsightsBundle\EventSubscriber\EventBufferFlushSubscriber} drains this queue into the main buffer and calls
 * {@see MauticEventBuffer::flush()} on both {@see \Symfony\Component\HttpKernel\KernelEvents::RESPONSE}
 * and {@see \Symfony\Component\HttpKernel\KernelEvents::TERMINATE} (main request). The first flush with pending rows sends HTTP; behaviour is the
 * same whether the integration durable / persistent buffer is enabled or not — {@see MauticEventBuffer} handles both.
 *
 * A microsecond {@code ts} is recorded in {@see enqueue()} at the moment the Mautic event fires, so the
 * outbound payload reflects the true event dispatch order and not the time of buffer flush.
 */
final class DeferredOutboundEventQueue
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $items = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(string $mauticEventName, array $payload): void
    {
        if (!\array_key_exists('ts', $payload)) {
            $payload['ts'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
        }

        $this->items[] = [$mauticEventName, $payload];
    }

    public function flushTo(MauticEventBuffer $buffer): void
    {
        foreach ($this->items as [$eventName, $payload]) {
            $buffer->push($eventName, $payload);
        }
        $this->items = [];
    }
}
