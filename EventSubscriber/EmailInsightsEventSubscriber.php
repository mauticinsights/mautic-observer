<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\EventSubscriber;

use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailEvent;
use Mautic\EmailBundle\Event\EmailOpenEvent;
use Mautic\EmailBundle\Event\EmailReplyEvent;
use Mautic\EmailBundle\Event\EmailSendEvent;
use Mautic\EmailBundle\Event\QueueEmailEvent;
use MauticPlugin\MauticInsightsBundle\Buffer\DeferredOutboundEventQueue;
use MauticPlugin\MauticInsightsBundle\Service\EmailInsightsContextBuilder;
use MauticPlugin\MauticInsightsBundle\Service\InsightsOutboundTokenProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Email entity lifecycle, opens/replies, sends, queue failures →
 * shared {@see DeferredOutboundEventQueue}, POST {@see \MauticPlugin\MauticInsightsBundle\InsightsApiConfig::EVENT_NOTIFY_URL}.
 */
final class EmailInsightsEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private DeferredOutboundEventQueue $deferredOutboundEventQueue,
        private EmailInsightsContextBuilder $emailContextBuilder,
        private InsightsOutboundTokenProvider $outboundTokenProvider,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EmailEvents::EMAIL_ON_OPEN => ['onEmailOpen', 0],
            EmailEvents::EMAIL_ON_SEND => ['onEmailSend', 0],
            EmailEvents::EMAIL_POST_SAVE => ['onEmailPostSave', 0],
            EmailEvents::EMAIL_POST_DELETE => ['onEmailPostDelete', 0],
            EmailEvents::EMAIL_ON_REPLY => ['onEmailReply', 0],
            EmailEvents::EMAIL_FAILED => ['onEmailFailed', 0],
        ];
    }

    public function onEmailOpen(EmailOpenEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $this->deferredOutboundEventQueue->enqueue(
            EmailEvents::EMAIL_ON_OPEN,
            $this->emailContextBuilder->buildEmailOpenPayload($event)
        );
    }

    public function onEmailSend(EmailSendEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $payload = $this->emailContextBuilder->buildEmailSendPayload($event);
        if (null !== $payload) {
            $this->deferredOutboundEventQueue->enqueue(EmailEvents::EMAIL_ON_SEND, $payload);
        }
    }

    /**
     * Inbound reply — Mautic 6+ dispatches {@see EmailReplyEvent} (not {@see EmailOpenEvent}).
     */
    public function onEmailReply(EmailReplyEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $this->deferredOutboundEventQueue->enqueue(
            EmailEvents::EMAIL_ON_REPLY,
            $this->emailContextBuilder->buildEmailReplyPayload($event)
        );
    }

    public function onEmailFailed(QueueEmailEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $this->deferredOutboundEventQueue->enqueue(
            EmailEvents::EMAIL_FAILED,
            $this->emailContextBuilder->buildQueueEmailPayload($event)
        );
    }

    /**
     * Entity CRUD sync — same row shape as segments ({@code email_id}, {@code properties} with {@code operation}).
     */
    public function onEmailPostSave(EmailEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $email = $event->getEmail();
        $id = $this->resolvePositiveEmailId($email);
        if (null === $id) {
            return;
        }

        $properties = [
            'name' => $email->getName(),
            'operation' => $event->isNew() ? 'created' : 'updated',
        ];

        $subject = $email->getSubject();
        if ('' !== \trim((string) $subject)) {
            $properties['subject'] = $subject;
        }

        if (method_exists($email, 'getEmailType')) {
            $properties['email_type'] = (string) $email->getEmailType();
        }

        if (method_exists($email, 'getIsPublished')) {
            $properties['is_published'] = (bool) $email->getIsPublished();
        }

        $segmentId = $this->resolvePrimarySegmentIdFromEmail($email);
        if (null !== $segmentId) {
            $properties['segment_id'] = $segmentId;
        }

        $this->deferredOutboundEventQueue->enqueue(EmailEvents::EMAIL_POST_SAVE, [
            'email_id' => $id,
            'properties' => $properties,
        ]);
    }

    public function onEmailPostDelete(EmailEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $email = $event->getEmail();
        $id = $this->resolvePositiveEmailId($email);
        if (null === $id) {
            return;
        }

        $this->deferredOutboundEventQueue->enqueue(EmailEvents::EMAIL_POST_DELETE, [
            'email_id' => $id,
            'properties' => [
                'name' => $email->getName(),
                'operation' => 'deleted',
            ],
        ]);
    }

    /**
     * First segment id from {@see \Mautic\EmailBundle\Entity\Email::getLists()} (many-to-many via {@code email_list_xref}).
     *
     * @param object $email {@see \Mautic\EmailBundle\Entity\Email}
     */
    private function resolvePrimarySegmentIdFromEmail(object $email): ?int
    {
        if (!method_exists($email, 'getLists')) {
            return null;
        }

        $lists = $email->getLists();
        if (null === $lists) {
            return null;
        }

        if (\is_object($lists) && method_exists($lists, 'isEmpty') && $lists->isEmpty()) {
            return null;
        }

        if (\is_object($lists) && method_exists($lists, 'toArray')) {
            $lists = $lists->toArray();
        }

        if (!\is_iterable($lists)) {
            return null;
        }

        foreach ($lists as $list) {
            if (!\is_object($list) || !method_exists($list, 'getId')) {
                continue;
            }
            $id = $list->getId();
            if (null !== $id && is_numeric($id)) {
                $n = (int) $id;
                if ($n > 0) {
                    return $n;
                }
            }
        }

        return null;
    }

    /**
     * After DB delete, {@see \Mautic\CoreBundle\Model\FormModel::deleteEntity()} clears {@code getId()} and sets {@code deletedId}.
     *
     * @param object $email {@see \Mautic\EmailBundle\Entity\Email}
     */
    private function resolvePositiveEmailId(object $email): ?int
    {
        // Prefer deletedId first — after FormModel::deleteEntity(), getId() is 0 in Mautic 6+.
        if (property_exists($email, 'deletedId')) {
            $vars = \get_object_vars($email);
            if (\array_key_exists('deletedId', $vars)) {
                $fromProp = $this->positiveIntOrNull($vars['deletedId']);
                if (null !== $fromProp) {
                    return $fromProp;
                }
            }
        }
        if (method_exists($email, 'getDeletedId')) {
            $fromGetter = $this->positiveIntOrNull($email->getDeletedId());
            if (null !== $fromGetter) {
                return $fromGetter;
            }
        }
        if (method_exists($email, 'getId')) {
            return $this->positiveIntOrNull($email->getId());
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
}
