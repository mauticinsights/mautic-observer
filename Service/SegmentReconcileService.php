<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use Doctrine\DBAL\Connection;
use MauticPlugin\MauticInsightsBundle\Buffer\MauticEventBuffer;
use Psr\Log\LoggerInterface;

/**
 * Diffs `lead_lists_leads` against the last-known checkpoint and emits
 * synthetic {@code mautic.lead_list_change} events for added/removed
 * segment memberships.
 *
 * Intended to be run as {@code mautic:insights:reconcile-segments} after
 * a `mautic:segments:update` (batch rebuild) that does not fire
 * `LEAD_LIST_CHANGE` events.
 */
final class SegmentReconcileService
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private Connection $mauticConnection,
        private MauticEventBuffer $eventBuffer,
        private SegmentCheckpointStore $checkpointStore,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Reconciles segment membership and emits synthetic events.
     *
     * @return array{added: int, removed: int, total_contacts: int}
     */
    public function reconcile(): array
    {
        $this->logger->info('MauticInsights: segment reconcile started.');

        $current = $this->loadCurrentMembership();
        $previous = $this->checkpointStore->load();

        $stats = ['added' => 0, 'removed' => 0, 'total_contacts' => \count($current)];

        $emitted = 0;

        // Process contacts that exist in current OR previous membership
        $allContactIds = array_unique(array_merge(array_keys($current), array_keys($previous)));
        sort($allContactIds, \SORT_NUMERIC);

        foreach (array_chunk($allContactIds, self::BATCH_SIZE) as $chunk) {
            // Load full segment membership for the whole batch to populate segment_ids accurately
            $fullMembership = $this->loadMembershipForContactIds($chunk);

            foreach ($chunk as $contactId) {
                $currentSegments = $current[$contactId] ?? [];
                $previousSegments = $previous[$contactId] ?? [];
                $allCurrentSegments = $fullMembership[$contactId] ?? $currentSegments;

                $added = array_diff($currentSegments, $previousSegments);
                $removed = array_diff($previousSegments, $currentSegments);

                foreach ($added as $segmentId) {
                    $this->emitListChange($contactId, $segmentId, true, $allCurrentSegments);
                    ++$stats['added'];
                    ++$emitted;
                }

                foreach ($removed as $segmentId) {
                    $this->emitListChange($contactId, $segmentId, false, $allCurrentSegments);
                    ++$stats['removed'];
                    ++$emitted;
                }
            }

            $this->logger->info(
                'MauticInsights: segment reconcile — processed {contacts} contacts, {emitted} events so far.',
                ['contacts' => \count($chunk), 'emitted' => $emitted]
            );
        }

        $this->checkpointStore->save($current);

        $this->logger->info(
            'MauticInsights: segment reconcile finished. added={added}, removed={removed}, total_contacts={total}.',
            $stats
        );

        return $stats;
    }

    /**
     * @return array<int, list<int>> contact_id → list of segment ids
     */
    private function loadCurrentMembership(): array
    {
        $rows = $this->mauticConnection->fetchAllAssociative(
            'SELECT lead_id, leadlist_id FROM lead_lists_leads WHERE manually_removed = 0'
        );

        $memberships = [];
        foreach ($rows as $row) {
            $contactId = (int) ($row['lead_id'] ?? 0);
            $listId = (int) ($row['leadlist_id'] ?? 0);
            if ($contactId <= 0 || $listId <= 0) {
                continue;
            }
            $memberships[$contactId][] = $listId;
        }

        // Deduplicate
        foreach ($memberships as $contactId => $listIds) {
            $memberships[$contactId] = array_values(array_unique($listIds));
        }

        return $memberships;
    }

    /**
     * @param list<int> $allCurrentSegmentIds all segment ids the contact currently belongs to
     */
    private function emitListChange(int $contactId, int $segmentId, bool $added, array $allCurrentSegmentIds): void
    {
        $this->eventBuffer->push('mautic.lead_list_change', [
            'contact_id' => $contactId,
            'segment_ids' => $allCurrentSegmentIds,
            'list_id' => $segmentId,
            'list_added' => $added,
        ]);
    }

    /**
     * @param list<int> $contactIds
     *
     * @return array<int, list<int>>
     */
    private function loadMembershipForContactIds(array $contactIds): array
    {
        if ([] === $contactIds) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, \count($contactIds), '?'));
        $rows = $this->mauticConnection->fetchAllAssociative(
            "SELECT lead_id, leadlist_id FROM lead_lists_leads WHERE manually_removed = 0 AND lead_id IN ($placeholders)",
            $contactIds,
        );

        $memberships = [];
        foreach ($rows as $row) {
            $contactId = (int) ($row['lead_id'] ?? 0);
            $listId = (int) ($row['leadlist_id'] ?? 0);
            if ($contactId <= 0 || $listId <= 0) {
                continue;
            }
            $memberships[$contactId][] = $listId;
        }

        foreach ($memberships as $cid => $listIds) {
            $memberships[$cid] = array_values(array_unique($listIds));
        }

        return $memberships;
    }
}
