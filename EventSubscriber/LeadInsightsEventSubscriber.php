<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\EventSubscriber;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Event\ChannelSubscriptionChange;
use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\Event\LeadMergeEvent;
use Mautic\LeadBundle\Event\ListChangeEvent;
use Mautic\LeadBundle\Event\PointsChangeEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\MauticInsightsBundle\Buffer\DeferredOutboundEventQueue;
use MauticPlugin\MauticInsightsBundle\Buffer\MauticEventBuffer;
use MauticPlugin\MauticInsightsBundle\Event\InsightsLeadPseudoEvents;
use MauticPlugin\MauticInsightsBundle\Service\InsightsOutboundTokenProvider;
use MauticPlugin\MauticInsightsBundle\Service\LeadInsightsContextBuilder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Lead and form activity → main {@see MauticEventBuffer} / {@see DeferredOutboundEventQueue} (POST {@see \MauticPlugin\MauticInsightsBundle\InsightsApiConfig::EVENT_NOTIFY_URL}, JSON root {@code events}). Mautic 6.x.
 */
class LeadInsightsEventSubscriber implements EventSubscriberInterface
{
    /**
     * Payloads captured on {@see LeadEvents::LEAD_PRE_DELETE} while {@see Lead::getId()} is still valid.
     * Keyed by contact id; consumed in {@see onLeadDelete()}.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $preDeletePayloads = [];

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
            // Mautic 7: after delete Lead::getId() is 0; capture full payload (incl. segments) while the PK is still set.
            LeadEvents::LEAD_PRE_DELETE => ['onLeadPreDelete', 0],
            LeadEvents::LEAD_POST_DELETE => ['onLeadDelete', 0],
            LeadEvents::LEAD_POST_MERGE => ['onLeadMerge', 0],
            LeadEvents::LEAD_POINTS_CHANGE => ['onPointsChange', 0],
            LeadEvents::LEAD_LIST_CHANGE => ['onListChange', 0],
            LeadEvents::LEAD_IDENTIFIED => ['onLeadIdentified', 0],
            LeadEvents::CHANNEL_SUBSCRIPTION_CHANGED => ['onChannelSubscriptionChanged', 0],
        ];
    }

    public function onLeadSave(LeadEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $lead = $event->getLead();
        if ($lead->isAnonymous()) {
            // Count-only: new anonymous → +1 on Insights instance totals. No profile/segment payload.
            // Updates of existing anonymous leads are ignored (no KPI impact).
            if ($event->isNew()) {
                $this->deferredLeadOutboundQueue->enqueue(
                    LeadEvents::LEAD_POST_SAVE,
                    $this->buildAnonymousCountPayload($lead, ['is_new' => true])
                );
            }

            return;
        }

        // All non-anonymous saves (not only isNew): Mautic often updates the same request after first persist; isNew-only missed real creates.
        $payload = $this->contextBuilder->buildPayloadFromLead($lead);

        if ($this->shouldEmitIsNewContactFlag($event, $lead)) {
            $payload = $this->withIsNewContactFlag($payload);
        }

        $this->deferredLeadOutboundQueue->enqueue(LeadEvents::LEAD_POST_SAVE, $payload);
        $this->enqueueLeadPseudoEventsFromPostSaveChanges($lead);
    }

    public function onLeadPreDelete(LeadEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $lead = $event->getLead();
        if ($lead->isAnonymous()) {
            return;
        }

        $id = $lead->getId();
        if ($id <= 0) {
            return;
        }

        $this->preDeletePayloads[$id] = $this->contextBuilder->buildPayloadFromLead($lead);
    }

    public function onLeadDelete(LeadEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $lead = $event->getLead();
        if ($lead->isAnonymous()) {
            $this->deferredLeadOutboundQueue->enqueue(
                LeadEvents::LEAD_POST_DELETE,
                $this->buildAnonymousCountPayload($lead)
            );

            return;
        }

        $id = $this->contextBuilder->resolvePositiveContactId($lead);
        $payload = null !== $id && isset($this->preDeletePayloads[$id])
            ? $this->preDeletePayloads[$id]
            : $this->contextBuilder->buildPayloadFromLead($lead);

        if (null !== $id) {
            unset($this->preDeletePayloads[$id]);
        }

        $this->deferredLeadOutboundQueue->enqueue(LeadEvents::LEAD_POST_DELETE, $payload);
    }

    /**
     * Contact merge — one contact is merged into another.
     *
     * {@see \Mautic\LeadBundle\Deduplicate\ContactMerger::merge()} dispatches LEAD_POST_MERGE
     * after the winner is saved but before the loser is deleted. We emit an event for the
     * merged-away (loser) contact so the app side can decrement contact counts and soft-delete
     * the loser's profile. The winner id is passed in {@code properties.survivor_id} for
     * {@see \App\Service\MauticIngestContactIdForStorage} to record as the storage contact.
     *
     * Payload {@code contact_id} = loser id; {@code properties.survivor_id} = winner id.
     * The app side expects the loser id at top-level {@code contact_id} for
     * {@see \App\Service\ContactsCountAdjustmentFromIngestEvent} (remove event) and
     * {@see \App\Service\ContactProfileFromLeadIngest} (soft-delete).
     */
    public function onLeadMerge(LeadMergeEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $loser = $event->getLoser();
        if ($loser->isAnonymous()) {
            // Count-only: loser was anonymous → −1 anonymous on Insights. No profile soft-delete payload.
            $extra = [];
            $survivorId = $event->getVictor()->getId();
            if ($survivorId > 0) {
                $extra['survivor_id'] = $survivorId;
            }
            $this->deferredLeadOutboundQueue->enqueue(
                LeadEvents::LEAD_POST_MERGE,
                $this->buildAnonymousCountPayload($loser, $extra)
            );

            return;
        }

        $extra = [];
        $survivorId = $event->getVictor()->getId();
        // Mautic 7 Lead::getId() returns 0 when unset — do not emit a fake survivor.
        if ($survivorId > 0) {
            $extra['survivor_id'] = $survivorId;
        }

        $this->deferredLeadOutboundQueue->enqueue(
            LeadEvents::LEAD_POST_MERGE,
            $this->mergeLeadProperties($loser, $extra)
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

    /**
     * Score change event — fired when Mautic modifies a contact's points.
     *
     * High-frequency: Mautic fires this per Point Action evaluation (email click, page visit, etc.).
     * We push through realtime {@see MauticEventBuffer} (not deferred) for immediate score velocity
     * visibility. Payload includes {@code old_points}, {@code new_points}, and {@code delta} so the
     * app can compute threshold crossings without comparing against snapshots.
     */
    public function onPointsChange(PointsChangeEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $lead = $event->getLead();
        if ($lead->isAnonymous()) {
            return;
        }

        $old = $event->getOldPoints();
        $new = $event->getNewPoints();
        $extra = [
            'old_points' => $old,
            'new_points' => $new,
            'delta' => $new - $old,
        ];

        $payload = $this->mergeLeadProperties($lead, $extra);
        $this->eventBuffer->push(LeadEvents::LEAD_POINTS_CHANGE, $payload);
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

    /**
     * Emits Insights-only pseudo-events when {@see Lead::getChanges()} reports tag or stage
     * mutations in the same request as {@see LeadEvents::LEAD_POST_SAVE}.
     *
     * Reads {@code $lead->getChanges()} only — not {@see \Mautic\CoreBundle\Event\CommonEvent::getChanges()}
     * on {@see LeadEvent}, because the latter snapshots entity changes and calls {@see Lead::resetChanges()},
     * which would break Mautic core listeners that rely on the entity change log (e.g. audit log).
     */
    private function enqueueLeadPseudoEventsFromPostSaveChanges(Lead $lead): void
    {
        /** @var array<string, mixed> $changes */
        $changes = $lead->getChanges();
        if ([] === $changes) {
            return;
        }

        if (isset($changes['tags']) && \is_array($changes['tags'])) {
            $added = self::normalizeDistinctStringList($changes['tags']['added'] ?? null);
            $removed = self::normalizeDistinctStringList($changes['tags']['removed'] ?? null);
            if ([] !== $added || [] !== $removed) {
                $this->deferredLeadOutboundQueue->enqueue(
                    InsightsLeadPseudoEvents::LEAD_TAGS_CHANGE,
                    $this->mergeLeadProperties($lead, [
                        'tags_added' => $added,
                        'tags_removed' => $removed,
                    ])
                );
            }
        }

        if (!isset($changes['stage'])) {
            return;
        }
        $pair = $changes['stage'];
        if (!\is_array($pair)) {
            return;
        }
        $values = array_values($pair);
        $oldRaw = $values[0] ?? null;
        $newRaw = $values[1] ?? null;
        $oldId = self::normalizePositiveIntOrNull($oldRaw);
        $newId = self::normalizePositiveIntOrNull($newRaw);
        if ($oldId === $newId) {
            return;
        }

        $this->deferredLeadOutboundQueue->enqueue(
            InsightsLeadPseudoEvents::LEAD_STAGE_CHANGE,
            $this->mergeLeadProperties($lead, [
                'stage_old_id' => $oldId,
                'stage_new_id' => $newId,
            ])
        );
    }

    /**
     * @return list<string>
     */
    private static function normalizeDistinctStringList(mixed $list): array
    {
        if (!\is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            if (!\is_string($item) || '' === $item) {
                continue;
            }
            $out[$item] = true;
        }
        ksort($out);

        return array_keys($out);
    }

    /**
     * Insights ingest increments running contact totals only when {@code properties.is_new}
     * is present on {@see LeadEvents::LEAD_POST_SAVE}. Mautic often fires a follow-up post_save
     * with {@see LeadEvent::isNew()} false when a lead becomes identified (the first persist was
     * anonymous and skipped by {@see self::onLeadSave()}) or when identification is applied in
     * the same request after the initial create save.
     */
    private function shouldEmitIsNewContactFlag(LeadEvent $event, Lead $lead): bool
    {
        if ($event->isNew()) {
            return true;
        }

        return self::changesMarkFirstIdentification($lead->getChanges());
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function withIsNewContactFlag(array $payload): array
    {
        $existing = $payload['properties'] ?? [];
        $payload['properties'] = \is_array($existing)
            ? array_merge($existing, ['is_new' => true])
            : ['is_new' => true];

        return $payload;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private static function changesMarkFirstIdentification(array $changes): bool
    {
        if (!isset($changes['dateIdentified'])) {
            return false;
        }

        $pair = $changes['dateIdentified'];
        if (!\is_array($pair)) {
            return false;
        }

        $values = array_values($pair);
        $oldRaw = $values[0] ?? null;
        $newRaw = $values[1] ?? null;

        if (null === $newRaw || '' === $newRaw || false === $newRaw) {
            return false;
        }

        return null === $oldRaw || '' === $oldRaw || false === $oldRaw;
    }

    /**
     * Minimal outbound body for anonymous lifecycle: Insights adjusts instance totals / snapshots only
     * and must not create {@code mautic_contact_profile} rows (no email/name/segment fields).
     *
     * @param array<string, mixed> $extraProperties
     *
     * @return array{channel: string, contact_id?: int, properties: array<string, mixed>}
     */
    private function buildAnonymousCountPayload(Lead $lead, array $extraProperties = []): array
    {
        $payload = [
            'channel' => 'contact',
            'properties' => array_merge(['is_anonymous' => true], $extraProperties),
        ];

        $id = $this->contextBuilder->resolvePositiveContactId($lead);
        if (null !== $id) {
            $payload['contact_id'] = $id;
        }

        return $payload;
    }

    private static function normalizePositiveIntOrNull(mixed $v): ?int
    {
        if (null === $v || false === $v) {
            return null;
        }
        if (\is_int($v)) {
            return $v > 0 ? $v : null;
        }
        if (\is_float($v)) {
            $i = (int) $v;

            return $i > 0 ? $i : null;
        }
        if (\is_string($v)) {
            $t = trim($v);
            if ('' === $t || !is_numeric($t)) {
                return null;
            }
            $i = (int) $t;

            return $i > 0 ? $i : null;
        }
        if (\is_object($v) && method_exists($v, 'getId')) {
            return self::normalizePositiveIntOrNull($v->getId());
        }

        return null;
    }
}
