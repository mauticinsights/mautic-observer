<?php

declare(strict_types=1);

namespace Mautic\CampaignBundle\Entity;

/**
 * Test double — campaign node/step (not a Symfony Event).
 */
final class Event
{
    /**
     * @param array<string, mixed> $properties
     */
    public function __construct(
        public string $type = '',
        public string $name = '',
        public ?int $id = null,
        public string $eventType = '',
        public int $order = 0,
        public string $description = '',
        public ?self $parent = null,
        public ?string $decisionPath = null,
        public ?string $channel = null,
        public ?int $channelId = null,
        public ?string $triggerMode = null,
        public array $properties = [],
    ) {
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getOrder(): int
    {
        return $this->order;
    }

    public function getDescription(): ?string
    {
        return '' === $this->description ? null : $this->description;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function getDecisionPath(): ?string
    {
        return $this->decisionPath;
    }

    public function getChannel(): ?string
    {
        return $this->channel;
    }

    public function getChannelId(): ?int
    {
        return $this->channelId;
    }

    public function getTriggerMode(): ?string
    {
        return $this->triggerMode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getProperties(): array
    {
        return $this->properties;
    }
}
