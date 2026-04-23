<?php

declare(strict_types=1);

namespace Mautic\CampaignBundle\Entity;

/**
 * Test double — campaign node/step (not a Symfony Event).
 */
final class Event
{
    public function __construct(
        public string $type = '',
        public string $name = '',
        public ?int $id = null,
        public string $eventType = '',
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
}
