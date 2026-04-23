<?php

declare(strict_types=1);

namespace Mautic\CampaignBundle\Entity;

use DateTimeInterface;
use Mautic\LeadBundle\Entity\Lead;

/**
 * Test double for campaign lead event log.
 */
final class LeadEventLog
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public ?Lead $lead = null,
        public ?Campaign $campaign = null,
        public ?Event $event = null,
        public ?DateTimeInterface $dateTriggered = null,
        public ?DateTimeInterface $triggerDate = null,
        public ?int $stubLogId = null,
        public int $rotation = 1,
        public ?bool $nonActionPathTaken = null,
        public array $metadata = [],
    ) {
    }

    public function getLead(): ?Lead
    {
        return $this->lead;
    }

    public function getCampaign(): ?Campaign
    {
        return $this->campaign;
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function getDateTriggered(): ?DateTimeInterface
    {
        return $this->dateTriggered;
    }

    public function getTriggerDate(): ?DateTimeInterface
    {
        return $this->triggerDate;
    }

    public function getId(): ?int
    {
        return $this->stubLogId;
    }

    public function getRotation(): int
    {
        return $this->rotation;
    }

    public function getNonActionPathTaken(): ?bool
    {
        return $this->nonActionPathTaken;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
