<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\EventSubscriber;

use Mautic\PageBundle\Event\PageHitEvent;
use Mautic\PageBundle\PageEvents;
use MauticPlugin\MauticInsightsBundle\Buffer\DeferredOutboundEventQueue;
use MauticPlugin\MauticInsightsBundle\Service\PageHitPayloadBuilder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Page hits → shared {@see DeferredOutboundEventQueue}, POST {@see \MauticPlugin\MauticInsightsBundle\InsightsApiConfig::EVENT_NOTIFY_URL}.
 *
 * The actual payload assembly (URL, UTM, geo, device, clickthrough, tracking_id, email_id, …)
 * lives in {@see PageHitPayloadBuilder} so it can be unit-tested independently and so the
 * subscriber stays a thin adapter between Mautic's dispatcher and the outbound queue.
 */
final class PageInsightsEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private DeferredOutboundEventQueue $deferredOutboundEventQueue,
        private PageHitPayloadBuilder $payloadBuilder,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PageEvents::PAGE_ON_HIT => ['onPageHit', 0],
        ];
    }

    public function onPageHit(PageHitEvent $event): void
    {
        $this->deferredOutboundEventQueue->enqueue(
            PageEvents::PAGE_ON_HIT,
            $this->payloadBuilder->buildPayload($event),
        );
    }
}
