<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use Psr\Log\LoggerInterface;

/**
 * Fetches all Mautic entities (segments, emails, campaigns, contacts) in pages of 1 000 and
 * pushes them to the Insights API via the single POST /event endpoint — same pipeline as live events.
 *
 * Sync order is important:
 *   1. Segments   — must exist in the app before contacts arrive.
 *   2. Emails     — referenced by campaign graph nodes.
 *   3. Campaigns  — includes full graph_events (nodes).
 *   4. Contacts   — {@code mautic.lead_post_save} with {@code segment_ids} for full membership snapshot.
 *   5. Finalize   — {@code mautic.sync_completed} pseudo-event carrying ID lists for reconciliation.
 *
 * A new sync_id UUID is generated at the start of each run via {@see SyncSessionManager::createNewSession()}.
 * It is embedded in every event batch payload root ({@code sync_id} key) and persisted in the
 * integration feature_settings so all subsequent live events also carry the same session identifier.
 */
final class BulkSyncService
{
    private const PAGE_SIZE = 1000;
    private const CHUNK_SIZE = 100;

    public function __construct(
        private Connection $mauticConnection,
        private ResilientEventSender $sender,
        private InsightsPublishedIntegrationFinder $integrationFinder,
        private InsightsHttpTokenResolver $httpTokenResolver,
        private SyncSessionManager $syncSessionManager,
        private LoggerInterface $logger,
    ) {
    }

    /** @var list<int> */
    private array $syncedSegmentIds = [];

    /** @var list<int> */
    private array $syncedEmailIds = [];

    /** @var list<int> */
    private array $syncedCampaignIds = [];

    /**
     * Runs the full sync: segments → emails → campaigns → contacts → mautic.sync_completed.
     *
     * A fresh sync_id is generated and persisted at the start of each run so the session
     * identifier is stable for all subsequent live events until the next sync.
     *
     * The finalization event ({@code mautic.sync_completed}) carries the complete set of seen IDs
     * for low-cardinality entity types so the app can soft-delete stale rows (entities that were
     * deleted while the plugin was disabled and never reported via normal *_post_delete events).
     *
     * Returns the total number of entities dispatched.
     */
    public function syncAll(): int
    {
        // Always fresh-resolve the token — InsightsOutboundTokenProvider caches the result once
        // per request, so it may hold a stale null from before the integration was saved as published.
        $integration = $this->integrationFinder->findPublished();
        if (null === $integration) {
            $this->logger->warning('MauticInsights BulkSync: integration not published — sync skipped.');

            return 0;
        }

        $token = $this->httpTokenResolver->resolve($integration);
        if ('' === $token) {
            $this->logger->warning('MauticInsights BulkSync: no outbound token — sync skipped.');

            return 0;
        }

        $syncId = $this->syncSessionManager->createNewSession();

        $this->syncedSegmentIds = [];
        $this->syncedEmailIds = [];
        $this->syncedCampaignIds = [];

        $total = 0;
        $total += $this->syncSegments($token, $syncId);
        $total += $this->syncEmails($token, $syncId);
        $total += $this->syncCampaigns($token, $syncId);
        $total += $this->syncContacts($token, $syncId);

        $this->sendFinalizationEvent($token, $syncId);

        $this->logger->info('MauticInsights BulkSync: completed.', [
            'total_entities' => $total,
            'sync_id' => $syncId,
        ]);

        return $total;
    }

    // -------------------------------------------------------------------------
    // Segments
    // -------------------------------------------------------------------------

    private function syncSegments(string $token, string $syncId): int
    {
        $ts = $this->nowUtcString();
        $offset = 0;
        $total = 0;

        while (true) {
            $rows = $this->mauticConnection->fetchAllAssociative(
                'SELECT id, name, alias, public_name, is_published, date_added, date_modified
                 FROM lead_lists
                 WHERE is_global = 1
                 ORDER BY id
                 LIMIT ? OFFSET ?',
                [self::PAGE_SIZE, $offset],
                [ParameterType::INTEGER, ParameterType::INTEGER],
            );

            if ([] === $rows) {
                break;
            }

            $events = [];
            foreach ($rows as $row) {
                $sid = (int) $row['id'];
                $this->syncedSegmentIds[] = $sid;
                $events[] = [
                    'event' => 'mautic.lead_list_post_save',
                    'ts' => $ts,
                    'segment_id' => $sid,
                    'properties' => [
                        'name' => $row['name'] ?? '',
                        'alias' => $row['alias'] ?? '',
                        'is_published' => (bool) ($row['is_published'] ?? false),
                        'public_name' => $row['public_name'] ?? null,
                        'operation' => 'sync',
                    ],
                ];
            }

            $total += $this->sendEventChunks($token, $syncId, $events, 'segments');
            $offset += self::PAGE_SIZE;

            if (\count($rows) < self::PAGE_SIZE) {
                break;
            }
        }

        return $total;
    }

    // -------------------------------------------------------------------------
    // Emails
    // -------------------------------------------------------------------------

    private function syncEmails(string $token, string $syncId): int
    {
        $ts = $this->nowUtcString();
        $offset = 0;
        $total = 0;

        while (true) {
            $rows = $this->mauticConnection->fetchAllAssociative(
                'SELECT id, name, subject, from_address, from_name, is_published, email_type,
                        date_added, date_modified
                 FROM emails
                 WHERE variant_parent_id IS NULL
                 ORDER BY id
                 LIMIT ? OFFSET ?',
                [self::PAGE_SIZE, $offset],
                [ParameterType::INTEGER, ParameterType::INTEGER],
            );

            if ([] === $rows) {
                break;
            }

            $events = [];
            foreach ($rows as $row) {
                $eid = (int) $row['id'];
                $this->syncedEmailIds[] = $eid;
                $events[] = [
                    'event' => 'mautic.email_post_save',
                    'ts' => $ts,
                    'email_id' => $eid,
                    'properties' => [
                        'name' => $row['name'] ?? '',
                        'subject' => $row['subject'] ?? null,
                        'from_address' => $row['from_address'] ?? null,
                        'from_name' => $row['from_name'] ?? null,
                        'is_published' => (bool) ($row['is_published'] ?? false),
                        'email_type' => $row['email_type'] ?? null,
                        'operation' => 'sync',
                    ],
                ];
            }

            $total += $this->sendEventChunks($token, $syncId, $events, 'emails');
            $offset += self::PAGE_SIZE;

            if (\count($rows) < self::PAGE_SIZE) {
                break;
            }
        }

        return $total;
    }

    // -------------------------------------------------------------------------
    // Campaigns (with graph events)
    // -------------------------------------------------------------------------

    private function syncCampaigns(string $token, string $syncId): int
    {
        $ts = $this->nowUtcString();
        $offset = 0;
        $total = 0;

        while (true) {
            $ids = $this->mauticConnection->fetchFirstColumn(
                'SELECT id FROM campaigns ORDER BY id LIMIT ? OFFSET ?',
                [self::PAGE_SIZE, $offset],
                [ParameterType::INTEGER, ParameterType::INTEGER],
            );

            if ([] === $ids) {
                break;
            }

            $campaignRows = $this->mauticConnection->fetchAllAssociative(
                'SELECT id, name, is_published FROM campaigns WHERE id IN ('.implode(',', array_fill(0, \count($ids), '?')).')',
                $ids,
            );

            $graphRows = $this->fetchCampaignGraphEvents($ids);

            $events = [];
            foreach ($campaignRows as $row) {
                $cid = (int) $row['id'];
                $this->syncedCampaignIds[] = $cid;
                $events[] = [
                    'event' => 'mautic.campaign_post_save',
                    'ts' => $ts,
                    'campaign_id' => $cid,
                    'properties' => [
                        'name' => $row['name'] ?? '',
                        'is_published' => (bool) ($row['is_published'] ?? false),
                        'operation' => 'sync',
                        'graph_events' => $graphRows[$cid] ?? [],
                    ],
                ];
            }

            $total += $this->sendEventChunks($token, $syncId, $events, 'campaigns');
            $offset += self::PAGE_SIZE;

            if (\count($ids) < self::PAGE_SIZE) {
                break;
            }
        }

        return $total;
    }

    /**
     * @param list<int|string> $campaignIds
     *
     * @return array<int, list<array<string, mixed>>> keyed by campaign_id
     */
    private function fetchCampaignGraphEvents(array $campaignIds): array
    {
        if ([] === $campaignIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, \count($campaignIds), '?'));
        $rows = $this->mauticConnection->fetchAllAssociative(
            'SELECT id, campaign_id, name, description, event_type, channel, channel_id,
                    trigger_mode, parent_id, decision_path, event_order, properties
             FROM campaign_events
             WHERE campaign_id IN ('.$placeholders.')
             ORDER BY campaign_id, event_order, id',
            $campaignIds,
        );

        $result = [];
        foreach ($rows as $row) {
            $cid = (int) $row['campaign_id'];
            $node = [
                'campaign_event_id' => (int) $row['id'],
                'name' => $row['name'] ?? '',
                'step_type' => $row['event_type'] ?? null,
                'event_type' => $row['event_type'] ?? null,
                'step_order' => isset($row['event_order']) ? (int) $row['event_order'] : null,
            ];

            if (null !== $row['description'] && '' !== $row['description']) {
                $node['description'] = $row['description'];
            }
            if (null !== $row['parent_id'] && (int) $row['parent_id'] > 0) {
                $node['parent_campaign_event_id'] = (int) $row['parent_id'];
            }
            if (null !== $row['decision_path'] && '' !== $row['decision_path']) {
                $node['decision_path'] = $row['decision_path'];
            }
            if (null !== $row['channel'] && '' !== $row['channel']) {
                $node['channel'] = $row['channel'];
            }
            if (isset($row['channel_id']) && (int) $row['channel_id'] > 0) {
                $node['channel_id'] = (int) $row['channel_id'];
            }
            if (null !== $row['trigger_mode'] && '' !== $row['trigger_mode']) {
                $node['trigger_mode'] = $row['trigger_mode'];
            }
            if (null !== $row['properties'] && '' !== $row['properties']) {
                $decoded = json_decode($row['properties'], true);
                if (\is_array($decoded) && [] !== $decoded) {
                    $node['properties'] = $decoded;
                }
            }

            $result[$cid][] = $node;
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Contacts
    // -------------------------------------------------------------------------

    /**
     * Syncs contacts as {@code mautic.lead_post_save} events via the standard POST /event endpoint.
     * Each event includes {@code segment_ids} so the app can perform a full membership replacement
     * in a single pass — no separate /sync/contacts endpoint required.
     */
    private function syncContacts(string $token, string $syncId): int
    {
        $ts = $this->nowUtcString();
        $offset = 0;
        $total = 0;

        while (true) {
            $leads = $this->mauticConnection->fetchAllAssociative(
                'SELECT l.id, l.email, l.firstname, l.lastname, l.company, l.phone, l.points,
                        l.date_identified
                 FROM leads l
                 WHERE l.is_published = 1 AND l.date_identified IS NOT NULL
                 ORDER BY l.id
                 LIMIT ? OFFSET ?',
                [self::PAGE_SIZE, $offset],
                [ParameterType::INTEGER, ParameterType::INTEGER],
            );

            if ([] === $leads) {
                break;
            }

            $ids = array_column($leads, 'id');
            $segmentMap = $this->fetchContactSegmentIds($ids);

            $events = [];
            foreach ($leads as $lead) {
                $cid = (int) $lead['id'];
                $props = ['operation' => 'sync'];
                if (null !== $lead['email'] && '' !== $lead['email']) {
                    $props['email'] = $lead['email'];
                }
                if (null !== $lead['firstname'] && '' !== $lead['firstname']) {
                    $props['first_name'] = $lead['firstname'];
                }
                if (null !== $lead['lastname'] && '' !== $lead['lastname']) {
                    $props['last_name'] = $lead['lastname'];
                }
                if (null !== $lead['company'] && '' !== $lead['company']) {
                    $props['company'] = $lead['company'];
                }
                if (null !== $lead['phone'] && '' !== $lead['phone']) {
                    $props['phone'] = $lead['phone'];
                }
                if (null !== $lead['points']) {
                    $props['points'] = (int) $lead['points'];
                }

                $events[] = [
                    'event' => 'mautic.lead_post_save',
                    'contact_id' => $cid,
                    'ts' => $ts,
                    'segment_ids' => $segmentMap[$cid] ?? [],
                    'properties' => $props,
                ];
            }

            $total += $this->sendEventChunks($token, $syncId, $events, 'contacts');
            $offset += self::PAGE_SIZE;

            if (\count($leads) < self::PAGE_SIZE) {
                break;
            }
        }

        return $total;
    }

    /**
     * @param list<int|string> $leadIds
     *
     * @return array<int, list<int>> keyed by contact_id
     */
    private function fetchContactSegmentIds(array $leadIds): array
    {
        if ([] === $leadIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, \count($leadIds), '?'));
        $rows = $this->mauticConnection->fetchAllAssociative(
            'SELECT lead_id, leadlist_id
             FROM lead_lists_leads
             WHERE lead_id IN ('.$placeholders.') AND manually_removed = 0',
            $leadIds,
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['lead_id']][] = (int) $row['leadlist_id'];
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Finalization
    // -------------------------------------------------------------------------

    /**
     * Sends a single {@code mautic.sync_completed} pseudo-event via POST /event.
     *
     * The event properties carry the complete set of IDs for low-cardinality entity types
     * (campaigns, segments, emails) so the app can soft-delete rows absent from Mautic
     * (deleted while the plugin was disabled and never reported via normal *_post_delete events).
     *
     * Contacts are intentionally excluded from reconciliation — with potentially millions of
     * contacts the ID list would be a prohibitively large payload and produce slow SQL anti-joins.
     * Contact deletes are handled by live {@code mautic.lead_post_delete} events.
     */
    private function sendFinalizationEvent(string $token, string $syncId): void
    {
        $props = ['sync_id' => $syncId];

        if ([] !== $this->syncedCampaignIds) {
            $props['campaigns'] = array_values(array_unique($this->syncedCampaignIds));
        }
        if ([] !== $this->syncedSegmentIds) {
            $props['segments'] = array_values(array_unique($this->syncedSegmentIds));
        }
        if ([] !== $this->syncedEmailIds) {
            $props['emails'] = array_values(array_unique($this->syncedEmailIds));
        }

        $event = [
            'event' => 'mautic.sync_completed',
            'ts' => $this->nowUtcString(),
            'properties' => $props,
        ];

        $payload = [
            'events' => [$event],
            'sync_id' => $syncId,
        ];

        $ok = $this->sender->send(InsightsApiConfig::EVENT_NOTIFY_URL, $token, $payload);
        if (!$ok) {
            $this->logger->error(
                'MauticInsights BulkSync: finalization event failed — stale entities may remain in the app.',
                ['sync_id' => $syncId],
            );
        }
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

    /**
     * @param list<array<string, mixed>> $events
     */
    private function sendEventChunks(string $token, string $syncId, array $events, string $type): int
    {
        $total = 0;
        foreach (array_chunk($events, self::CHUNK_SIZE) as $chunk) {
            $payload = ['events' => $chunk, 'sync_id' => $syncId];
            $ok = $this->sender->send(InsightsApiConfig::EVENT_NOTIFY_URL, $token, $payload);
            if (!$ok) {
                $this->logger->error(
                    'MauticInsights BulkSync: chunk send failed.',
                    ['type' => $type, 'count' => \count($chunk), 'sync_id' => $syncId],
                );
            } else {
                $total += \count($chunk);
            }
        }

        return $total;
    }

    private function nowUtcString(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }
}
