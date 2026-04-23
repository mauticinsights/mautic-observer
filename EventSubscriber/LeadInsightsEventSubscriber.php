<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\EventSubscriber;

use Mautic\FormBundle\Event\SubmissionEvent;
use Mautic\FormBundle\FormEvents;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Event\ChannelSubscriptionChange;
use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\Event\ListChangeEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\MauticInsightsBundle\Buffer\DeferredOutboundEventQueue;
use MauticPlugin\MauticInsightsBundle\Buffer\MauticEventBuffer;
use MauticPlugin\MauticInsightsBundle\Service\InsightsOutboundTokenProvider;
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
        private InsightsOutboundTokenProvider $outboundTokenProvider,
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
            // LEAD_POINTS_CHANGE is intentionally not subscribed – MVP on the Insights side
            // (funnel + realtime) has no scoring metrics. Re-add with `onPointsChange`
            // when an MQL threshold dashboard, score velocity, or score-stagnation cohort
            // feature is introduced.
            LeadEvents::LEAD_LIST_CHANGE => ['onListChange', 0],
            LeadEvents::LEAD_IDENTIFIED => ['onLeadIdentified', 0],
            LeadEvents::CHANNEL_SUBSCRIPTION_CHANGED => ['onChannelSubscriptionChanged', 0],
            FormEvents::FORM_ON_SUBMIT => ['onFormSubmit', 0],
        ];
    }

    public function onLeadSave(LeadEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

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
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $lead = $event->getLead();
        if ($lead->isAnonymous()) {
            return;
        }

        $this->deferredLeadOutboundQueue->enqueue(
            LeadEvents::LEAD_POST_DELETE,
            $this->contextBuilder->buildPayloadFromLead($lead)
        );
    }

    /**
     * Segment membership change.
     *
     * Coverage caveat — this handler does NOT see changes made by `mautic:segments:update`
     * (alias `mautic:segments:rebuild`) or any other code path that calls
     * {@see \Mautic\LeadBundle\Model\ListModel::addLead()} / {@see \Mautic\LeadBundle\Model\ListModel::removeLead()}
     * with `$batchProcess = true`. Mautic core short-circuits event dispatch in batch mode
     * (see ListModel::addLead() line ~645 and ListModel::removeLead() line ~770: the
     * `if ($batchProcess) { detach } elseif (hasListeners LEAD_LIST_CHANGE) { dispatch }` branch).
     * This is intentional upstream behaviour to avoid flooding integrations during multi-million-row rebuilds.
     *
     * Signals we DO receive real-time:
     *   - manual UI add/remove from a segment
     *   - form-submit / API contact save that re-evaluates smart segment filters (non-batch path)
     *   - contact merge reassignment
     *
     * Signals we MISS:
     *   - `mautic:segments:update` cron / manual CLI rebuild
     *   - smart-segment filter edits that trigger server-side batch reassignment
     *   - bulk CSV imports followed by scheduled segment rebuild
     *   - any code path that passes `$batchProcess = true` to addLead / removeLead
     *
     * If this drift becomes product-visible (e.g. stale segment counts after an admin edits
     * a segment filter), add a reconcile cron — either an `mautic:insights:reconcile-segments`
     * observer command that diffs `lead_lists_leads` against last known state and POSTs a
     * snapshot, or a periodic pull on the Insights API side against Mautic's segment endpoints.
     */
    public function onListChange(ListChangeEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

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
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $lead = $event->getLead();
        if ($lead->isAnonymous()) {
            return;
        }

        $this->eventBuffer->push(LeadEvents::LEAD_IDENTIFIED, $this->contextBuilder->buildPayloadFromLead($lead));
    }

    /**
     * DNC (Do-Not-Contact) status transition per communication channel (email, sms, …).
     *
     * Fired by Mautic on:
     *   - user-triggered unsubscribe / resubscribe (preference-center, one-click unsubscribe header)
     *   - bounce processor flipping status to "bounced"
     *   - admin manual override via contact detail UI
     *
     * Status codes mirror {@see \Mautic\LeadBundle\Entity\DoNotContact}:
     *   0 = contactable, 1 = unsubscribed, 2 = bounced, 3 = manual.
     *
     * Payload shape (consumed by {@see \App\MessageHandler\IngestMauticEventHandler}):
     *   - top-level `channel` = the DNC channel (e.g. "email", "sms") – persisted onto
     *     {@see \App\Entity\MauticEvent::$channel}.
     *   - `properties.dnc_old_status` / `dnc_new_status` (int) – canonical, stable across Mautic versions.
     *   - `properties.dnc_old_status_verb` / `dnc_new_status_verb` (string) – human-readable
     *     for direct dashboard rendering without a lookup table.
     *   - Plus all standard lead-derived props (points snapshot, utm tags when present).
     *
     * We push directly through {@see MauticEventBuffer} (not the deferred queue): subscription
     * changes are low-frequency and high-value, so realtime surfacing outweighs batch amortisation.
     */
    public function onChannelSubscriptionChanged(ChannelSubscriptionChange $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $lead = $event->getLead();
        if ($lead->isAnonymous()) {
            return;
        }

        $channel = $event->getChannel();
        $extra = [
            'dnc_old_status' => $event->getOldStatus(),
            'dnc_new_status' => $event->getNewStatus(),
            'dnc_old_status_verb' => $event->getOldStatusVerb(),
            'dnc_new_status_verb' => $event->getNewStatusVerb(),
        ];

        $payload = $this->mergeLeadProperties($lead, $extra);
        $payload['channel'] = $channel;

        $this->eventBuffer->push(LeadEvents::CHANNEL_SUBSCRIPTION_CHANGED, $payload);
    }

    public function onFormSubmit(SubmissionEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

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
