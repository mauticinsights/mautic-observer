<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Throwable;

final class LeadInsightsContextBuilder
{
    public function __construct(
        private LeadModel $leadModel,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayloadFromLead(Lead $lead): array
    {
        return \array_merge(
            [
                'channel' => 'contact',
            ],
            $this->buildLeadAugmentation($lead)
        );
    }

    /**
     * Contact fields without `count` / `channel` (for merging into email payloads).
     *
     * @return array<string, mixed>
     */
    public function buildLeadAugmentation(Lead $lead): array
    {
        $payload = [];

        $id = $this->resolvePositiveContactId($lead);
        if (null !== $id) {
            $payload['contact_id'] = $id;
        }

        $segmentIds = $this->resolveSegmentIds($lead);
        if ([] !== $segmentIds) {
            $payload['segment_ids'] = $segmentIds;
        }

        $properties = $this->buildProperties($lead);
        if ([] !== $properties) {
            $payload['properties'] = $properties;
        }

        return $payload;
    }

    /**
     * After DB delete, {@see \Mautic\CoreBundle\Model\FormModel::deleteEntity} sets {@see \Mautic\CoreBundle\Entity\FormEntity::$deletedId}
     * to the former primary key. In Mautic 7 {@see Lead::getId()} is typed {@code int} and returns {@code 0} once Doctrine
     * clears the PK — never treat 0 as a real contact id. Prefer public {@code $deletedId} (same as IntegrationsBundle).
     */
    public function resolvePositiveContactId(Lead $lead): ?int
    {
        if (isset($lead->deletedId)) {
            $deletedId = (int) $lead->deletedId;
            if ($deletedId > 0) {
                return $deletedId;
            }
        }

        if (method_exists($lead, 'getDeletedId')) {
            $viaGetter = $lead->getDeletedId();
            if (\is_int($viaGetter) && $viaGetter > 0) {
                return $viaGetter;
            }
        }

        $id = $lead->getId();

        return $id > 0 ? $id : null;
    }

    /**
     * @return list<int>
     */
    private function resolveSegmentIds(Lead $lead): array
    {
        // LeadModel::getLists() uses getId(); after delete that is 0 and would query lead_id = 0.
        if ($lead->getId() <= 0) {
            return [];
        }

        try {
            $lists = $this->leadModel->getLists($lead, true, true);
        } catch (Throwable) {
            return [];
        }

        $ids = [];
        foreach ($lists as $list) {
            if (\is_array($list) && isset($list['id'])) {
                $ids[] = (int) $list['id'];
            } elseif (\is_object($list) && method_exists($list, 'getId')) {
                $lid = $list->getId();
                if (null !== $lid) {
                    $ids[] = (int) $lid;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProperties(Lead $lead): array
    {
        $out = [];

        $email = $lead->getEmail();
        if (null !== $email && '' !== $email) {
            $out['email'] = $email;
        }

        $primary = $lead->getPrimaryIdentifier();
        if (null !== $primary && '' !== $primary) {
            $out['primary_identifier'] = $primary;
        }

        $company = $lead->getCompany();
        if (null !== $company && '' !== $company) {
            $out['company'] = $company;
        }

        $first = $lead->getFirstname();
        if (null !== $first && '' !== $first) {
            $out['first_name'] = $first;
        }

        $last = $lead->getLastname();
        if (null !== $last && '' !== $last) {
            $out['last_name'] = $last;
        }

        $phone = $lead->getPhone();
        if (\is_string($phone)) {
            $phone = trim($phone);
        } else {
            $phone = null;
        }
        if (null !== $phone && '' !== $phone) {
            $out['phone'] = $phone;
        }

        // After delete, Doctrine associations resolve with lead_id NULL/0 — skip the useless query.
        if ($lead->getId() > 0) {
            $tags = [];
            try {
                foreach ($lead->getTags() as $tag) {
                    if (\is_object($tag) && method_exists($tag, 'getTag')) {
                        $t = $tag->getTag();
                        if (\is_string($t) && '' !== $t) {
                            $tags[] = $t;
                        }
                    }
                }
            } catch (Throwable) {
                $tags = [];
            }
            if ([] !== $tags) {
                $out['tags'] = $tags;
            }
        }

        // Current lead scoring snapshot (not a delta). Mautic's Lead::$points is a scalar int field
        // on the entity, so this is a free read (no DB round-trip). Cheap enrichment unlocks coarse
        // scoring analytics on any existing event (e.g. "form submits for contacts with points ≥ 30",
        // or time-to-threshold via MIN(ts) WHERE points ≥ X) without subscribing to LEAD_POINTS_CHANGE.
        // Note: during a request that mutates points, the value seen at LEAD_POST_SAVE may lag the
        // scoring trigger by one dispatcher pass; acceptable fuzz for analytics, not for real-time alerting.
        $points = $lead->getPoints();
        if (\is_int($points)) {
            $out['points'] = $points;
        }

        return $out;
    }
}
