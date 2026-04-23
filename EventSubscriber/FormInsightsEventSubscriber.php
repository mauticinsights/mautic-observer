<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\EventSubscriber;

use Mautic\FormBundle\Event\SubmissionEvent;
use Mautic\FormBundle\FormEvents;
use MauticPlugin\MauticInsightsBundle\Buffer\DeferredOutboundEventQueue;
use MauticPlugin\MauticInsightsBundle\Service\FormSubmitPayloadBuilder;
use MauticPlugin\MauticInsightsBundle\Service\InsightsOutboundTokenProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Standalone form analytics path: {@see FormEvents::FORM_ON_SUBMIT} → {@see DeferredOutboundEventQueue}
 * → POST {@see \MauticPlugin\MauticInsightsBundle\InsightsApiConfig::EVENT_NOTIFY_URL}.
 *
 * Payload shape is built in {@see FormSubmitPayloadBuilder}. Lead lifecycle events remain in
 * {@see LeadInsightsEventSubscriber}; we do not subscribe to form entity CRUD here (only submit).
 */
final class FormInsightsEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private DeferredOutboundEventQueue $deferredOutboundEventQueue,
        private FormSubmitPayloadBuilder $formSubmitPayloadBuilder,
        private InsightsOutboundTokenProvider $outboundTokenProvider,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FormEvents::FORM_ON_SUBMIT => ['onFormSubmit', 0],
        ];
    }

    public function onFormSubmit(SubmissionEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $this->deferredOutboundEventQueue->enqueue(
            FormEvents::FORM_ON_SUBMIT,
            $this->formSubmitPayloadBuilder->buildPayload($event)
        );
    }
}
