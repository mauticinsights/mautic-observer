<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\EmailBundle\Event\EmailOpenEvent;
use Mautic\EmailBundle\Event\EmailSendEvent;
use Mautic\EmailBundle\Event\QueueEmailEvent;
use Mautic\EmailBundle\Event\TransportWebhookEvent;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PointBundle\Event\TriggerExecutedEvent;

final class EmailInsightsContextBuilder
{
    public function __construct(
        private LeadInsightsContextBuilder $leadInsights,
    ) {
    }

    /**
     * @return array<string, mixed>|null skip internal sends and sends without a lead
     */
    public function buildEmailSendPayload(EmailSendEvent $event): ?array
    {
        if ($event->isInternalSend() || null === $event->getLead()) {
            return null;
        }

        $payload = [
            'channel' => 'email',
        ];

        $email = $event->getEmail();
        if (null !== $email) {
            $eid = $email->getId();
            if (null !== $eid) {
                $payload['email_id'] = (int) $eid;
            }
            $this->addEmailTemplateProperties($payload, $email);
        }

        $campaignId = $this->campaignIdFromTupleSource($event->getSource());
        if (null !== $campaignId) {
            $payload['campaign_id'] = $campaignId;
        }

        $this->mergeLeadFromSendEvent($payload, $event->getLead());

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildEmailOpenPayload(EmailOpenEvent $event): array
    {
        $payload = [
            'channel' => 'email',
        ];

        $email = $event->getEmail();
        if (null !== $email) {
            $eid = $email->getId();
            if (null !== $eid) {
                $payload['email_id'] = (int) $eid;
            }
            $this->addEmailTemplateProperties($payload, $email);
        }

        $stat = $event->getStat();
        $campaignId = $this->campaignIdFromStatSource($stat);
        if (null !== $campaignId) {
            $payload['campaign_id'] = $campaignId;
        }

        $listId = $this->segmentIdFromStatList($stat);
        $this->mergeLeadAndStatSegment($payload, $stat->getLead(), $listId);

        $this->appendOpenProperties($payload, $event);

        return $payload;
    }

    /**
     * Failed / resend queue — metadata from {@see \Mautic\EmailBundle\Mailer\Message\MauticMessage}.
     *
     * @return array<string, mixed>
     */
    public function buildQueueEmailPayload(QueueEmailEvent $event): array
    {
        $message = $event->getMessage();
        $properties = [];

        if (method_exists($message, 'getLeadIdHash')) {
            $hash = $message->getLeadIdHash();
            if (null !== $hash && '' !== $hash) {
                $properties['lead_id_hash'] = $hash;
            }
        }

        if (method_exists($message, 'getSubject')) {
            $subject = $message->getSubject();
            if ('' !== \trim((string) $subject)) {
                $properties['subject'] = (string) $subject;
            }
        }

        if (method_exists($message, 'getFrom')) {
            $from = $message->getFrom();
            if (\is_array($from) && [] !== $from) {
                $properties['from'] = $this->mimeAddressesToStrings($from);
            }
        }

        if (method_exists($message, 'getTo')) {
            $to = $message->getTo();
            if (\is_array($to) && [] !== $to) {
                $properties['to'] = $this->mimeAddressesToStrings($to);
            }
        }

        return [
            'channel' => 'email',
            'properties' => $properties,
        ];
    }

    /**
     * Transport provider webhook — request line only (no body; avoids leaking secrets).
     *
     * @return array<string, mixed>
     */
    public function buildTransportWebhookPayload(TransportWebhookEvent $event): array
    {
        $request = $event->getRequest();

        return [
            'channel' => 'email',
            'properties' => [
                'http_method' => $request->getMethod(),
                'path' => $request->getPathInfo(),
            ],
        ];
    }

    /**
     * Point trigger “email to user” — {@see \Mautic\EmailBundle\EmailEvents::ON_SENT_EMAIL_TO_USER}.
     *
     * @return array<string, mixed>|null
     */
    public function buildSentEmailToUserPayload(TriggerExecutedEvent $event): ?array
    {
        $lead = $event->getLead();
        $leadId = $lead->getId();
        if (null === $leadId || $leadId <= 0) {
            return null;
        }

        $te = $event->getTriggerEvent();
        $properties = [];
        if (method_exists($te, 'getId')) {
            $tid = $te->getId();
            if (null !== $tid) {
                $properties['point_trigger_event_id'] = (int) $tid;
            }
        }
        if (method_exists($te, 'getName')) {
            $properties['point_trigger_event_name'] = (string) $te->getName();
        }
        if (method_exists($te, 'getType')) {
            $properties['point_trigger_event_type'] = (string) $te->getType();
        }

        $payload = [
            'channel' => 'email',
            'contact_id' => (int) $leadId,
            'properties' => $properties,
        ];
        $this->mergeAugmentation($payload, $this->leadInsights->buildLeadAugmentation($lead));

        return $payload;
    }

    /**
     * @param list<object|string> $addresses
     *
     * @return list<string>
     */
    private function mimeAddressesToStrings(array $addresses): array
    {
        $out = [];
        foreach ($addresses as $a) {
            if (\is_object($a) && method_exists($a, 'getAddress')) {
                $out[] = (string) $a->getAddress();
            } else {
                $out[] = (string) $a;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function appendOpenProperties(array &$payload, EmailOpenEvent $event): void
    {
        $props = $payload['properties'] ?? [];
        if (!\is_array($props)) {
            $props = [];
        }
        $props['first_open'] = $event->isFirstTime();
        $payload['properties'] = $props;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function addEmailTemplateProperties(array &$payload, Email $email): void
    {
        $props = $payload['properties'] ?? [];
        if (!\is_array($props)) {
            $props = [];
        }

        $name = $email->getName();
        if ('' !== \trim((string) $name)) {
            $props['email_name'] = $name;
        }

        $subject = $email->getSubject();
        if ('' !== \trim((string) $subject)) {
            $props['subject'] = $subject;
        }

        if ([] !== $props) {
            $payload['properties'] = $props;
        }
    }

    /**
     * @param array<string, mixed>           $payload
     * @param Lead|array<string, mixed>|null $lead
     */
    private function mergeLeadFromSendEvent(array &$payload, Lead|array|null $lead): void
    {
        if ($lead instanceof Lead) {
            $this->mergeAugmentation($payload, $this->leadInsights->buildLeadAugmentation($lead));

            return;
        }

        if (\is_array($lead) && isset($lead['id']) && is_numeric($lead['id'])) {
            $payload['contact_id'] = (int) $lead['id'];
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mergeLeadAndStatSegment(array &$payload, ?Lead $lead, ?int $statListId): void
    {
        $fromStat = [];
        if (null !== $statListId) {
            $fromStat[] = $statListId;
        }

        if ($lead instanceof Lead) {
            $aug = $this->leadInsights->buildLeadAugmentation($lead);
            $fromLead = [];
            if (isset($aug['segment_ids']) && \is_array($aug['segment_ids'])) {
                $fromLead = $aug['segment_ids'];
            }
            unset($aug['segment_ids']);
            $this->mergeAugmentation($payload, $aug);

            $merged = array_values(array_unique([...$fromLead, ...$fromStat]));
            if ([] !== $merged) {
                $payload['segment_ids'] = $merged;
            }

            return;
        }

        if ([] !== $fromStat) {
            $payload['segment_ids'] = $fromStat;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $aug
     */
    private function mergeAugmentation(array &$payload, array $aug): void
    {
        foreach ($aug as $key => $value) {
            if ('properties' === $key && \is_array($value)) {
                $base = $payload['properties'] ?? [];
                if (!\is_array($base)) {
                    $base = [];
                }
                $payload['properties'] = \array_merge($base, $value);

                continue;
            }
            if (!isset($payload[$key])) {
                $payload[$key] = $value;
            }
        }
    }

    private function campaignIdFromTupleSource(mixed $source): ?int
    {
        if (!\is_array($source) || !isset($source[0], $source[1]) || 'campaign' !== $source[0]) {
            return null;
        }

        if (!is_numeric($source[1])) {
            return null;
        }

        return (int) $source[1];
    }

    private function campaignIdFromStatSource(Stat $stat): ?int
    {
        if ('campaign' !== $stat->getSource()) {
            return null;
        }

        $sid = $stat->getSourceId();
        if (null === $sid) {
            return null;
        }

        return (int) $sid;
    }

    private function segmentIdFromStatList(Stat $stat): ?int
    {
        $list = $stat->getList();
        if (!\is_object($list) || !method_exists($list, 'getId')) {
            return null;
        }

        $id = $list->getId();
        if (null === $id) {
            return null;
        }

        return (int) $id;
    }
}
