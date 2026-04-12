<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\EventSubscriber;

use MauticPlugin\MauticInsightsBundle\Buffer\DeferredOutboundEventQueue;
use MauticPlugin\MauticInsightsBundle\Buffer\MauticEventBuffer;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Service\InsightsApiSendGate;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Drains {@see DeferredOutboundEventQueue} into the main {@see MauticEventBuffer} then flushes POST {@see InsightsApiConfig::EVENT_NOTIFY_URL}.
 * Works with any {@see \MauticPlugin\MauticInsightsBundle\Buffer\PersistentEventBufferInterface} configuration (durable buffer on or off).
 */
final class EventBufferFlushSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MauticEventBuffer $mauticEventBuffer,
        private DeferredOutboundEventQueue $deferredOutboundEventQueue,
        private InsightsApiSendGate $insightsApiSendGate,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -2048],
            KernelEvents::TERMINATE => ['onKernelTerminate', -2048],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->flushOutbound();
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->flushOutbound();
    }

    private function flushOutbound(): void
    {
        $this->insightsApiSendGate->tick();
        $this->deferredOutboundEventQueue->flushTo($this->mauticEventBuffer);
        $this->mauticEventBuffer->flush();
    }
}
