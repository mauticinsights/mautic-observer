<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

/**
 * Serializes Mautic {@code Campaign::getEvents()} into JSON-friendly rows for {@code mautic.campaign_post_save.properties.graph_events}.
 */
final class CampaignGraphEventsSerializer
{
    /**
     * @return list<array<string, mixed>>
     */
    public function serialize(object $campaign): array
    {
        if (!\method_exists($campaign, 'getEvents')) {
            return [];
        }

        $collection = $campaign->getEvents();
        if (null === $collection) {
            return [];
        }

        $rows = [];
        foreach ($collection as $eventEntity) {
            if (!\is_object($eventEntity) || !\method_exists($eventEntity, 'getId')) {
                continue;
            }

            $eid = $this->positiveIntFromMixed($eventEntity->getId());
            if (null === $eid) {
                continue;
            }

            $row = [
                'campaign_event_id' => $eid,
                'name' => \method_exists($eventEntity, 'getName') ? (string) $eventEntity->getName() : '',
            ];

            if (\method_exists($eventEntity, 'getDescription')) {
                $desc = $eventEntity->getDescription();
                if (null !== $desc && '' !== \trim((string) $desc)) {
                    $row['description'] = (string) $desc;
                }
            }

            if (\method_exists($eventEntity, 'getType')) {
                $row['step_type'] = (string) $eventEntity->getType();
            }

            if (\method_exists($eventEntity, 'getEventType')) {
                $row['event_type'] = (string) $eventEntity->getEventType();
            }

            if (\method_exists($eventEntity, 'getOrder')) {
                $ord = $eventEntity->getOrder();
                if (\is_int($ord)) {
                    $row['step_order'] = $ord;
                }
            }

            if (\method_exists($eventEntity, 'getDecisionPath')) {
                $dp = $eventEntity->getDecisionPath();
                if (null !== $dp && '' !== \trim((string) $dp)) {
                    $row['decision_path'] = (string) $dp;
                }
            }

            if (\method_exists($eventEntity, 'getParent')) {
                $parent = $eventEntity->getParent();
                if (\is_object($parent) && \method_exists($parent, 'getId')) {
                    $pid = $this->positiveIntFromMixed($parent->getId());
                    if (null !== $pid) {
                        $row['parent_campaign_event_id'] = $pid;
                    }
                }
            }

            if (\method_exists($eventEntity, 'getChannel')) {
                $ch = $eventEntity->getChannel();
                if (null !== $ch && '' !== \trim((string) $ch)) {
                    $row['channel'] = (string) $ch;
                }
            }

            if (\method_exists($eventEntity, 'getChannelId')) {
                $chid = $eventEntity->getChannelId();
                $n = $this->positiveIntFromMixed($chid);
                if (null !== $n) {
                    $row['channel_id'] = $n;
                }
            }

            if (\method_exists($eventEntity, 'getTriggerMode')) {
                $tm = $eventEntity->getTriggerMode();
                if (null !== $tm && '' !== \trim((string) $tm)) {
                    $row['trigger_mode'] = (string) $tm;
                }
            }

            if (\method_exists($eventEntity, 'getProperties')) {
                $props = $eventEntity->getProperties();
                if (\is_array($props) && [] !== $props) {
                    $row['properties'] = $props;
                }
            }

            $rows[] = $row;
        }

        \usort($rows, static function (array $a, array $b): int {
            $oa = $a['step_order'] ?? PHP_INT_MAX;
            $ob = $b['step_order'] ?? PHP_INT_MAX;

            return $oa <=> $ob;
        });

        return $rows;
    }

    private function positiveIntFromMixed(mixed $raw): ?int
    {
        if (\is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }
        if (\is_string($raw) && is_numeric($raw)) {
            $n = (int) $raw;

            return $n > 0 ? $n : null;
        }

        return null;
    }
}
