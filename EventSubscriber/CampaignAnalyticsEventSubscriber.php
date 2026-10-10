<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\EventSubscriber;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\CampaignEvent;
use Mautic\CampaignBundle\Event\CampaignLeadChangeEvent;
use Mautic\CampaignBundle\Event\ExecutedBatchEvent;
use Mautic\CampaignBundle\Event\ExecutedEvent;
use Mautic\CampaignBundle\Event\FailedEvent;
use Mautic\CampaignBundle\Event\ScheduledEvent;
use MauticPlugin\MauticInsightsBundle\Buffer\DeferredOutboundEventQueue;
use MauticPlugin\MauticInsightsBundle\Buffer\MauticEventBuffer;
use MauticPlugin\MauticInsightsBundle\Service\CampaignGraphEventsSerializer;
use MauticPlugin\MauticInsightsBundle\Service\InsightsOutboundTokenProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Campaigns → Insights event buffer (same outbound channel as lead/email events).
 *
 * {@see CampaignEvents::CAMPAIGN_POST_SAVE} and {@see CampaignEvents::CAMPAIGN_POST_DELETE} use the shared
 * {@see DeferredOutboundEventQueue} (like lead save/delete) so they batch on flush; other handlers use {@see MauticEventBuffer::push()} directly.
 *
 * @see CampaignEvents
 */
final class CampaignAnalyticsEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MauticEventBuffer $eventBuffer,
        private DeferredOutboundEventQueue $deferredCampaignOutboundQueue,
        private InsightsOutboundTokenProvider $outboundTokenProvider,
        private CampaignGraphEventsSerializer $campaignGraphEventsSerializer,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_POST_SAVE => ['onCampaignPostSave', 0],
            CampaignEvents::CAMPAIGN_POST_DELETE => ['onCampaignPostDelete', 0],
            CampaignEvents::CAMPAIGN_ON_LEADCHANGE => ['onCampaignLeadChange', 0],
            CampaignEvents::ON_EVENT_EXECUTED => ['onEventExecuted', 0],
            CampaignEvents::ON_EVENT_EXECUTED_BATCH => ['onEventExecutedBatch', 0],
            CampaignEvents::ON_EVENT_FAILED => ['onEventFailed', 0],
            CampaignEvents::ON_EVENT_SCHEDULED => ['onEventScheduled', 0],
        ];
    }

    public function onCampaignPostSave(CampaignEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $campaign = $event->getCampaign();
        $id = $this->resolvePositiveCampaignId($campaign);
        if (null === $id) {
            return;
        }

        $properties = [
            'name' => $campaign->getName(),
            'operation' => $event->isNew() ? 'created' : 'updated',
            'is_published' => $campaign->isPublished(),
        ];

        $properties['graph_events'] = $this->campaignGraphEventsSerializer->serialize($campaign);

        $this->deferredCampaignOutboundQueue->enqueue(CampaignEvents::CAMPAIGN_POST_SAVE, [
            'campaign_id' => $id,
            'properties' => $properties,
        ]);
    }

    public function onCampaignPostDelete(CampaignEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $campaign = $event->getCampaign();
        $id = $this->resolvePositiveCampaignId($campaign);
        if (null === $id) {
            return;
        }

        $this->deferredCampaignOutboundQueue->enqueue(CampaignEvents::CAMPAIGN_POST_DELETE, [
            'campaign_id' => $id,
            'properties' => [
                'name' => $campaign->getName(),
            ],
        ]);
    }

    /**
     * After DB delete, {@see \Mautic\CoreBundle\Model\FormModel::deleteEntity()} clears {@code getId()} and sets {@see \Mautic\CoreBundle\Entity\FormEntity::$deletedId}.
     */
    private function resolvePositiveCampaignId(object $campaign): ?int
    {
        // Prefer deletedId first — after FormModel::deleteEntity(), getId() is 0 in Mautic 6+.
        if (property_exists($campaign, 'deletedId')) {
            $vars = \get_object_vars($campaign);
            if (\array_key_exists('deletedId', $vars)) {
                $fromProp = $this->positiveIntOrNull($vars['deletedId']);
                if (null !== $fromProp) {
                    return $fromProp;
                }
            }
        }
        if (method_exists($campaign, 'getDeletedId')) {
            $fromGetter = $this->positiveIntOrNull($campaign->getDeletedId());
            if (null !== $fromGetter) {
                return $fromGetter;
            }
        }
        if (method_exists($campaign, 'getId')) {
            return $this->positiveIntOrNull($campaign->getId());
        }

        return null;
    }

    private function positiveIntOrNull(mixed $raw): ?int
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

    public function onCampaignLeadChange(CampaignLeadChangeEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $action = $event->getAction();
        if (null === $action || '' === $action) {
            return;
        }

        $campaignId = $this->resolvePositiveCampaignId($event->getCampaign());
        if (null === $campaignId) {
            return;
        }

        $eventName = 'campaign.'.$action;
        $leads = $event->getLeads();
        if (null !== $leads && [] !== $leads) {
            foreach ($leads as $lead) {
                $this->pushCampaignLeadMembership($eventName, $lead->getId(), $campaignId);
            }

            return;
        }

        $lead = $event->getLead();
        if (null === $lead) {
            return;
        }

        $this->pushCampaignLeadMembership($eventName, $lead->getId(), $campaignId);
    }

    public function onEventExecuted(ExecutedEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $this->pushStepExecutedFromLog($event->getLog());
    }

    public function onEventExecutedBatch(ExecutedBatchEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        foreach ($event->getExecuted() as $log) {
            $this->pushStepExecutedFromLog($log);
        }
    }

    public function onEventFailed(FailedEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $this->pushStepFailedFromLog($event->getLog());
    }

    public function onEventScheduled(ScheduledEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $this->pushStepScheduledFromLog($event);
    }

    private function pushCampaignLeadMembership(string $eventName, ?int $contactId, int $campaignId): void
    {
        if (null === $contactId || $contactId <= 0 || $campaignId <= 0) {
            return;
        }

        $this->eventBuffer->push($eventName, [
            'channel' => 'campaign',
            'contact_id' => $contactId,
            'campaign_id' => $campaignId,
        ]);
    }

    private function pushStepExecutedFromLog(LeadEventLog $log): void
    {
        $lead = $log->getLead();
        $campaign = $log->getCampaign();
        $eventEntity = $log->getEvent();
        if (null === $lead || null === $campaign || null === $eventEntity) {
            return;
        }

        $leadId = $lead->getId();
        $campaignId = $campaign->getId();
        if (!$leadId || !$campaignId) {
            return;
        }

        $data = [
            'channel' => 'campaign',
            'contact_id' => $leadId,
            'campaign_id' => $campaignId,
            'properties' => $this->buildCampaignGraphStepProperties($log, $eventEntity),
        ];

        $triggered = $log->getDateTriggered();
        if (null !== $triggered) {
            $data['ts'] = $triggered->format(\DATE_ATOM);
        }

        $this->eventBuffer->push('campaign.step_executed', $data);
    }

    private function pushStepFailedFromLog(LeadEventLog $log): void
    {
        $lead = $log->getLead();
        $campaign = $log->getCampaign();
        $eventEntity = $log->getEvent();
        if (null === $lead || null === $campaign || null === $eventEntity) {
            return;
        }

        $leadId = $lead->getId();
        $campaignId = $campaign->getId();
        if (!$leadId || !$campaignId) {
            return;
        }

        $data = [
            'channel' => 'campaign',
            'contact_id' => $leadId,
            'campaign_id' => $campaignId,
            'properties' => $this->buildCampaignGraphStepProperties($log, $eventEntity),
        ];

        $triggered = $log->getDateTriggered();
        if (null !== $triggered) {
            $data['ts'] = $triggered->format(\DATE_ATOM);
        }

        $this->eventBuffer->push('campaign.step_failed', $data);
    }

    private function pushStepScheduledFromLog(ScheduledEvent $event): void
    {
        $log = $event->getLog();
        $lead = $log->getLead();
        $campaign = $log->getCampaign();
        $eventEntity = $log->getEvent();
        if (null === $lead || null === $campaign || null === $eventEntity) {
            return;
        }

        $leadId = $lead->getId();
        $campaignId = $campaign->getId();
        if (!$leadId || !$campaignId) {
            return;
        }

        $properties = $this->buildCampaignGraphStepProperties($log, $eventEntity);
        $properties['is_reschedule'] = $event->isReschedule();

        $data = [
            'channel' => 'campaign',
            'contact_id' => $leadId,
            'campaign_id' => $campaignId,
            'properties' => $properties,
        ];

        $when = $log->getTriggerDate();
        if (null !== $when) {
            $data['ts'] = $when->format(\DATE_ATOM);
        }

        $this->eventBuffer->push('campaign.step_scheduled', $data);
    }

    /**
     * Funnel context: Mautic {@code campaign_events.id}, log row id, rotation, decision path, metadata.
     *
     * @return array<string, mixed>
     */
    private function buildCampaignGraphStepProperties(LeadEventLog $log, object $eventEntity): array
    {
        $properties = [
            'step_type' => $eventEntity->getType(),
            'step_name' => $eventEntity->getName(),
        ];

        $eventId = $this->positiveIntFromIdGetter($eventEntity);
        if (null !== $eventId) {
            $properties['event_id'] = $eventId;
        }

        if (method_exists($eventEntity, 'getEventType')) {
            $eventType = $eventEntity->getEventType();
            if (\is_string($eventType) && '' !== \trim($eventType)) {
                $properties['event_type'] = $eventType;
            }
        }

        $logId = $this->positiveIntFromIdGetter($log);
        if (null !== $logId) {
            $properties['lead_event_log_id'] = $logId;
        }

        if (method_exists($log, 'getRotation')) {
            $rotation = $log->getRotation();
            if (\is_int($rotation)) {
                $properties['rotation'] = $rotation;
            }
        }

        if (method_exists($log, 'getNonActionPathTaken')) {
            $nonAction = $log->getNonActionPathTaken();
            if (\is_bool($nonAction)) {
                $properties['non_action_path_taken'] = $nonAction;
            }
        }

        if (method_exists($log, 'getMetadata')) {
            $metadata = $log->getMetadata();
            if (\is_array($metadata) && [] !== $metadata) {
                $properties['metadata'] = $metadata;
            }
        }

        return $properties;
    }

    private function positiveIntFromIdGetter(object $entity): ?int
    {
        if (!method_exists($entity, 'getId')) {
            return null;
        }

        $raw = $entity->getId();
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
