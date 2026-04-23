<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\EventSubscriber;

use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailEvent;
use Mautic\EmailBundle\Event\EmailOpenEvent;
use Mautic\EmailBundle\Event\EmailSendEvent;
use Mautic\EmailBundle\Event\QueueEmailEvent;
use Mautic\EmailBundle\Event\TransportWebhookEvent;
use Mautic\PointBundle\Event\TriggerExecutedEvent;
use MauticPlugin\MauticInsightsBundle\Buffer\DeferredOutboundEventQueue;
use MauticPlugin\MauticInsightsBundle\Service\EmailInsightsContextBuilder;
use MauticPlugin\MauticInsightsBundle\Service\InsightsOutboundTokenProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Email entity lifecycle, opens/replies, sends/display, queue fail/resend, transport webhooks, point-trigger mail →
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
            EmailEvents::EMAIL_ON_DISPLAY => ['onEmailDisplay', 0],
            EmailEvents::EMAIL_POST_SAVE => ['onEmailPostSave', 0],
            EmailEvents::EMAIL_POST_DELETE => ['onEmailPostDelete', 0],
            EmailEvents::EMAIL_ON_REPLY => ['onEmailReply', 0],
            EmailEvents::EMAIL_FAILED => ['onEmailFailed', 0],
            EmailEvents::EMAIL_RESEND => ['onEmailResend', 0],
            EmailEvents::ON_SENT_EMAIL_TO_USER => ['onSentEmailToUser', 0],
            EmailEvents::ON_TRANSPORT_WEBHOOK => ['onTransportWebhook', 0],
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
     * Browser preview of email HTML — same payload shape as {@see EmailEvents::EMAIL_ON_SEND} ({@see EmailInsightsContextBuilder::buildEmailSendPayload}).
     */
    public function onEmailDisplay(EmailSendEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $payload = $this->emailContextBuilder->buildEmailSendPayload($event);
        if (null !== $payload) {
            $this->deferredOutboundEventQueue->enqueue(EmailEvents::EMAIL_ON_DISPLAY, $payload);
        }
    }

    /**
     * Inbound reply — same {@see EmailOpenEvent} context as {@see EmailEvents::EMAIL_ON_OPEN}.
     */
    public function onEmailReply(EmailOpenEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $this->deferredOutboundEventQueue->enqueue(
            EmailEvents::EMAIL_ON_REPLY,
            $this->emailContextBuilder->buildEmailOpenPayload($event)
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

    public function onEmailResend(QueueEmailEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $this->deferredOutboundEventQueue->enqueue(
            EmailEvents::EMAIL_RESEND,
            $this->emailContextBuilder->buildQueueEmailPayload($event)
        );
    }

    public function onSentEmailToUser(TriggerExecutedEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $payload = $this->emailContextBuilder->buildSentEmailToUserPayload($event);
        if (null !== $payload) {
            $this->deferredOutboundEventQueue->enqueue(EmailEvents::ON_SENT_EMAIL_TO_USER, $payload);
        }
    }

    public function onTransportWebhook(TransportWebhookEvent $event): void
    {
        if (null === $this->outboundTokenProvider->getToken()) {
            return;
        }

        $this->deferredOutboundEventQueue->enqueue(
            EmailEvents::ON_TRANSPORT_WEBHOOK,
            $this->emailContextBuilder->buildTransportWebhookPayload($event)
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
        $candidates = [];
        if (method_exists($email, 'getId')) {
            $candidates[] = $email->getId();
        }
        if (method_exists($email, 'getDeletedId')) {
            $candidates[] = $email->getDeletedId();
        }
        if (property_exists($email, 'deletedId')) {
            $vars = \get_object_vars($email);
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
