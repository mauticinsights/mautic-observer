<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\EventSubscriber;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\PageBundle\Event\PageHitEvent;
use Mautic\PageBundle\PageEvents;
use MauticPlugin\MauticInsightsBundle\Buffer\DeferredOutboundEventQueue;
use MauticPlugin\MauticInsightsBundle\Service\LeadInsightsContextBuilder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Page hits → shared {@see DeferredOutboundEventQueue}, POST {@see \MauticPlugin\MauticInsightsBundle\InsightsApiConfig::EVENT_NOTIFY_URL}.
 */
final class PageInsightsEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private DeferredOutboundEventQueue $deferredOutboundEventQueue,
        private LeadInsightsContextBuilder $contextBuilder,
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
        $payload = [
            'properties' => [],
        ];

        $page = $event->getPage();
        if (null !== $page && method_exists($page, 'getId')) {
            $pageId = $page->getId();
            if (null !== $pageId) {
                $payload['properties']['page_id'] = $pageId;
            }
        }

        $hit = $event->getHit();
        if (method_exists($hit, 'getLead')) {
            $lead = $hit->getLead();
            if ($lead instanceof Lead && !$lead->isAnonymous()) {
                $aug = $this->contextBuilder->buildLeadAugmentation($lead);
                $fromLeadProps = $aug['properties'] ?? [];
                unset($aug['properties']);
                $payload = array_merge($payload, $aug);
                if ([] !== $fromLeadProps) {
                    $payload['properties'] = array_merge($payload['properties'], $fromLeadProps);
                }
            }
        }

        $this->deferredOutboundEventQueue->enqueue(PageEvents::PAGE_ON_HIT, $payload);
    }
}
