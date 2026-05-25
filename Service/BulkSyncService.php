<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use Psr\Log\LoggerInterface;

/**
 * Fetches all Mautic entities (segments, emails, campaigns, contacts) in pages of 1 000 and
 * pushes them to the Insights API using the same payload format as live event subscribers.
 *
 * Sync order is important:
 *   1. Segments   — referenced by contacts; must exist in the app before contacts arrive.
 *   2. Emails     — referenced by campaign graph nodes.
 *   3. Campaigns  — includes full graph_events (nodes); upserted via POST /event.
 *   4. Contacts   — with current segment_ids snapshot; sent to POST /sync/contacts.
 *
 * Each entity type is sent in chunks of {@see CHUNK_SIZE} per HTTP request via {@see ResilientEventSender}.
 * Contact pages are fetched with a joined segment-membership query to minimise round trips.
 */
final class BulkSyncService
{
    private const PAGE_SIZE = 1000;
    private const CHUNK_SIZE = 100;

    public function __construct(
        private Connection $mauticConnection,
        private ResilientEventSender $sender,
        private InsightsOutboundTokenProvider $outboundTokenProvider,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Runs the full sync in order: segments → emails → campaigns → contacts.
     * Returns the total number of entities dispatched across all types.
     */
    public function syncAll(): int
    {
        $token = $this->outboundTokenProvider->getToken();
        if (null === $token) {
            $this->logger->warning('MauticInsights BulkSync: no outbound token — sync skipped.');

            return 0;
        }

        $total = 0;
        $total += $this->syncSegments($token);
        $total += $this->syncEmails($token);
        $total += $this->syncCampaigns($token);
        $total += $this->syncContacts($token);

        $this->logger->info('MauticInsights BulkSync: completed.', ['total_entities' => $total]);

        return $total;
    }

    // -------------------------------------------------------------------------
    // Segments
    // -------------------------------------------------------------------------

    private function syncSegments(string $token): int
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
            );

            if ([] === $rows) {
                break;
            }

            $events = [];
            foreach ($rows as $row) {
                $events[] = [
                    'event' => 'mautic.lead_list_post_save',
                    'ts' => $ts,
                    'segment_id' => (int) $row['id'],
                    'properties' => [
                        'name' => $row['name'] ?? '',
                        'alias' => $row['alias'] ?? '',
                        'is_published' => (bool) ($row['is_published'] ?? false),
                        'public_name' => $row['public_name'] ?? null,
                        'operation' => 'sync',
                    ],
                ];
            }

            $total += $this->sendEventChunks($token, $events, 'segments');
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

    private function syncEmails(string $token): int
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
            );

            if ([] === $rows) {
                break;
            }

            $events = [];
            foreach ($rows as $row) {
                $events[] = [
                    'event' => 'mautic.email_post_save',
                    'ts' => $ts,
                    'email_id' => (int) $row['id'],
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

            $total += $this->sendEventChunks($token, $events, 'emails');
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

    private function syncCampaigns(string $token): int
    {
        $ts = $this->nowUtcString();
        $offset = 0;
        $total = 0;

        while (true) {
            $ids = $this->mauticConnection->fetchFirstColumn(
                'SELECT id FROM campaigns ORDER BY id LIMIT ? OFFSET ?',
                [self::PAGE_SIZE, $offset],
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

            $total += $this->sendEventChunks($token, $events, 'campaigns');
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
     * @return array<int, list<array<string, mixed>>>  keyed by campaign_id
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

    private function syncContacts(string $token): int
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
            );

            if ([] === $leads) {
                break;
            }

            $ids = array_column($leads, 'id');
            $segmentMap = $this->fetchContactSegmentIds($ids);

            $contacts = [];
            foreach ($leads as $lead) {
                $cid = (int) $lead['id'];
                $props = [];
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

                $contacts[] = [
                    'contact_id' => $cid,
                    'ts' => $ts,
                    'segment_ids' => $segmentMap[$cid] ?? [],
                    'properties' => $props,
                ];
            }

            $total += $this->sendContactChunks($token, $contacts);
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
     * @return array<int, list<int>>  keyed by contact_id
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
    // HTTP helpers
    // -------------------------------------------------------------------------

    /**
     * @param list<array<string, mixed>> $events
     */
    private function sendEventChunks(string $token, array $events, string $type): int
    {
        $total = 0;
        foreach (array_chunk($events, self::CHUNK_SIZE) as $chunk) {
            $ok = $this->sender->sendEvents(InsightsApiConfig::EVENT_NOTIFY_URL, $token, $chunk);
            if (!$ok) {
                $this->logger->error(
                    'MauticInsights BulkSync: chunk send failed.',
                    ['type' => $type, 'count' => \count($chunk)],
                );
            } else {
                $total += \count($chunk);
            }
        }

        return $total;
    }

    /**
     * @param list<array<string, mixed>> $contacts
     */
    private function sendContactChunks(string $token, array $contacts): int
    {
        $total = 0;
        foreach (array_chunk($contacts, self::CHUNK_SIZE) as $chunk) {
            $ok = $this->sender->send(
                InsightsApiConfig::SYNC_CONTACTS_URL,
                $token,
                ['contacts' => $chunk],
            );
            if (!$ok) {
                $this->logger->error(
                    'MauticInsights BulkSync: contact chunk send failed.',
                    ['count' => \count($chunk)],
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
