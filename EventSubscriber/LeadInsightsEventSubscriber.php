<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\EventSubscriber;

use Mautic\FormBundle\Event\SubmissionEvent;
use Mautic\FormBundle\FormEvents;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\Event\ListChangeEvent;
use Mautic\LeadBundle\Event\PointsChangeEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\MauticInsightsBundle\Buffer\DeferredOutboundEventQueue;
use MauticPlugin\MauticInsightsBundle\Buffer\MauticEventBuffer;
use MauticPlugin\MauticInsightsBundle\Service\LeadInsightsContextBuilder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Lead and form activity → main {@see MauticEventBuffer} / {@see DeferredOutboundEventQueue} (POST {@see \MauticPlugin\MauticInsightsBundle\InsightsApiConfig::EVENT_NOTIFY_URL}, JSON root {@code events}). Mautic 5.x.
 */
class LeadInsightsEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MauticEventBuffer $eventBuffer,
        private LeadInsightsContextBuilder $contextBuilder,
        private DeferredOutboundEventQueue $deferredLeadOutboundQueue,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // High priority: core {@see \Mautic\LeadBundle\EventListener\LeadSubscriber::onLeadPostSave()} re-dispatches
            // {@see LeadEvents::LEAD_IDENTIFIED} synchronously inside its own LEAD_POST_SAVE handler, which would cause
            // our LEAD_IDENTIFIED row to get a ts a few ms earlier than our LEAD_POST_SAVE row. Running first keeps
            // LEAD_POST_SAVE's ts strictly before the nested LEAD_IDENTIFIED ts, matching Mautic's actual top-level dispatch order.
            LeadEvents::LEAD_POST_SAVE => ['onLeadSave', 255],
            LeadEvents::LEAD_POST_DELETE => ['onLeadDelete', 0],
            LeadEvents::LEAD_POINTS_CHANGE => ['onPointsChange', 0],
            LeadEvents::LEAD_LIST_CHANGE => ['onListChange', 0],
            LeadEvents::LEAD_IDENTIFIED => ['onLeadIdentified', 0],
            FormEvents::FORM_ON_SUBMIT => ['onFormSubmit', 0],
        ];
    }

    public function onLeadSave(LeadEvent $event): void
    {
        $lead = $event->getLead();
        if ($lead->isAnonymous()) {
            return;
        }

        // All non-anonymous saves (not only isNew): Mautic often updates the same request after first persist; isNew-only missed real creates.
        $payload = $this->contextBuilder->buildPayloadFromLead($lead);

        // Mark new contacts so the API side can distinguish creates from updates for contact counting.
        if ($event->isNew()) {
            $existing = $payload['properties'] ?? [];
            $payload['properties'] = \is_array($existing)
                ? array_merge($existing, ['is_new' => true])
                : ['is_new' => true];
        }

        $this->deferredLeadOutboundQueue->enqueue(LeadEvents::LEAD_POST_SAVE, $payload);
    }

    public function onLeadDelete(LeadEvent $event): void
    {
        $lead = $event->getLead();
        if ($lead->isAnonymous()) {
            return;
        }

        $this->deferredLeadOutboundQueue->enqueue(
            LeadEvents::LEAD_POST_DELETE,
            $this->contextBuilder->buildPayloadFromLead($lead)
        );
    }

    public function onPointsChange(PointsChangeEvent $event): void
    {
        $lead = $event->getLead();
        if ($lead->isAnonymous()) {
            return;
        }

        $payload = $this->mergeLeadProperties($lead, [
            'old_points' => $event->getOldPoints(),
            'new_points' => $event->getNewPoints(),
        ]);
        $this->eventBuffer->push(LeadEvents::LEAD_POINTS_CHANGE, $payload);
    }

    public function onListChange(ListChangeEvent $event): void
    {
        $batch = $event->getLeads();
        if (\is_array($batch) && [] !== $batch) {
            foreach ($batch as $lead) {
                if ($lead instanceof Lead) {
                    $this->pushListChangeForLead($event, $lead);
                }
            }

            return;
        }

        $lead = $event->getLead();
        if ($lead instanceof Lead) {
            $this->pushListChangeForLead($event, $lead);
        }
    }

    public function onLeadIdentified(LeadEvent $event): void
    {
        $lead = $event->getLead();
        if ($lead->isAnonymous()) {
            return;
        }

        $this->eventBuffer->push(LeadEvents::LEAD_IDENTIFIED, $this->contextBuilder->buildPayloadFromLead($lead));
    }

    public function onFormSubmit(SubmissionEvent $event): void
    {
        $submission = $event->getSubmission();
        $payload = [
            'channel' => 'form',
            'properties' => [],
        ];

        if (method_exists($submission, 'getForm')) {
            $form = $submission->getForm();
            if (\is_object($form) && method_exists($form, 'getId')) {
                $formId = $form->getId();
                if (null !== $formId) {
                    $payload['properties']['form_id'] = $formId;
                }
            }
        }

        if (method_exists($submission, 'getLead')) {
            $lead = $submission->getLead();
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

        $this->eventBuffer->push(FormEvents::FORM_ON_SUBMIT, $payload);
    }

    private function pushListChangeForLead(ListChangeEvent $event, Lead $lead): void
    {
        if ($lead->isAnonymous()) {
            return;
        }

        $list = $event->getList();
        $extra = ['list_added' => $event->wasAdded()];
        if (method_exists($list, 'getId')) {
            $listId = $list->getId();
            if (null !== $listId) {
                $extra['list_id'] = $listId;
            }
        }

        $payload = $this->mergeLeadProperties($lead, $extra);
        $this->eventBuffer->push(LeadEvents::LEAD_LIST_CHANGE, $payload);
    }

    /**
     * @param array<string, mixed> $propertyExtras
     *
     * @return array<string, mixed>
     */
    private function mergeLeadProperties(Lead $lead, array $propertyExtras): array
    {
        $base = $this->contextBuilder->buildPayloadFromLead($lead);
        if ([] === $propertyExtras) {
            return $base;
        }

        $existing = $base['properties'] ?? [];
        if (!\is_array($existing)) {
            $existing = [];
        }
        $base['properties'] = array_merge($existing, $propertyExtras);

        return $base;
    }
}
