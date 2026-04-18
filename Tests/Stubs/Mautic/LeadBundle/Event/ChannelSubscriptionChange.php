<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\Event;

use Mautic\LeadBundle\Entity\Lead;

/**
 * Test double for {@see ChannelSubscriptionChange}.
 *
 * Reason codes mirror {@see \Mautic\LeadBundle\Entity\DoNotContact}:
 *   0 = IS_CONTACTABLE, 1 = UNSUBSCRIBED, 2 = BOUNCED, 3 = MANUAL.
 *
 * Verb mapping is reproduced here so tests don't need the (private) core impl —
 * the observer subscriber reads verbs off the event directly.
 */
class ChannelSubscriptionChange
{
    public function __construct(
        private Lead $lead,
        private string $channel,
        private int $oldStatus,
        private int $newStatus,
    ) {
    }

    public function getLead(): Lead
    {
        return $this->lead;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getOldStatus(): int
    {
        return $this->oldStatus;
    }

    public function getNewStatus(): int
    {
        return $this->newStatus;
    }

    public function getOldStatusVerb(): string
    {
        return $this->reasonVerb($this->oldStatus);
    }

    public function getNewStatusVerb(): string
    {
        return $this->reasonVerb($this->newStatus);
    }

    private function reasonVerb(int $reason): string
    {
        return match ($reason) {
            0 => 'contactable',
            2 => 'bounced',
            3 => 'manual',
            default => 'unsubscribed',
        };
    }
}
