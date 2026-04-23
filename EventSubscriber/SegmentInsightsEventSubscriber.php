<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\EventSubscriber;

use Mautic\LeadBundle\Event\LeadListEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\MauticInsightsBundle\Buffer\DeferredOutboundEventQueue;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Service\InsightsOutboundTokenProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Segments (lead lists) → shared {@see DeferredOutboundEventQueue}, same POST {@see InsightsApiConfig::EVENT_NOTIFY_URL}
 * and JSON shape as campaign CRUD (entity id, {@code properties} including {@code operation}, {@code is_published} on save).
 *
 * @see LeadEvents::LIST_POST_SAVE
 * @see LeadEvents::LIST_POST_DELETE
 */
final class SegmentInsightsEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private DeferredOutboundEventQueue $deferredOutboundEventQueue,
        private InsightsOutboundTokenProvider $outboundTokenProvider,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LeadEvents::LIST_POST_SAVE => ['onListPostSave', 0],
            LeadEvents::LIST_POST_DELETE => ['onListPostDelete', 0],
        ];
    }

    public function onListPostSave(LeadListEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $list = $event->getList();
        $id = $this->resolvePositiveLeadListId($list);
        if (null === $id) {
            return;
        }

        $properties = [
            'name' => $list->getName(),
            'alias' => $list->getAlias(),
            'operation' => $event->isNew() ? 'created' : 'updated',
        ];

        $publicName = $list->getPublicName();
        if (null !== $publicName && '' !== $publicName) {
            $properties['public_name'] = $publicName;
        }

        if (method_exists($list, 'getIsPublished')) {
            $properties['is_published'] = (bool) $list->getIsPublished();
        }

        $this->deferredOutboundEventQueue->enqueue(LeadEvents::LIST_POST_SAVE, [
            'segment_id' => $id,
            'properties' => $properties,
        ]);
    }

    public function onListPostDelete(LeadListEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $list = $event->getList();
        $id = $this->resolvePositiveLeadListId($list);
        if (null === $id) {
            return;
        }

        $this->deferredOutboundEventQueue->enqueue(LeadEvents::LIST_POST_DELETE, [
            'segment_id' => $id,
            'properties' => [
                'name' => $list->getName(),
                'operation' => 'deleted',
            ],
        ]);
    }

    /**
     * After DB delete, {@see \Mautic\CoreBundle\Model\FormModel::deleteEntity()} clears {@code getId()} and sets {@see \Mautic\CoreBundle\Entity\FormEntity::$deletedId}.
     */
    private function resolvePositiveLeadListId(object $list): ?int
    {
        $candidates = [];
        if (method_exists($list, 'getId')) {
            $candidates[] = $list->getId();
        }
        if (method_exists($list, 'getDeletedId')) {
            $candidates[] = $list->getDeletedId();
        }
        if (property_exists($list, 'deletedId')) {
            $vars = \get_object_vars($list);
            if (\array_key_exists('deletedId', $vars)) {
                $candidates[] = $vars['deletedId'];
            }
        }

        foreach ($candidates as $raw) {
            if (null === $raw) {
                continue;
            }
            if (\is_int($raw)) {
                if ($raw > 0) {
                    return $raw;
                }

                continue;
            }
            if (\is_string($raw) && is_numeric($raw)) {
                $n = (int) $raw;
                if ($n > 0) {
                    return $n;
                }
            }
        }

        return null;
    }
}
