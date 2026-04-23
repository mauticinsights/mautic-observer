<?php

declare(strict_types=1);

namespace Mautic\CampaignBundle\Entity;

/**
 * Test double for campaign lifecycle.
 */
final class Campaign
{
    /** Mirrors {@see \Mautic\CoreBundle\Entity\FormEntity::$deletedId} after {@see \Mautic\CoreBundle\Model\FormModel::deleteEntity()}. */
    public ?int $deletedId = null;

    /** @var list<Event> */
    private array $events;

    /**
     * @param list<Event> $events
     */
    public function __construct(
        public ?int $id = null,
        public string $name = '',
        private bool $published = true,
        array $events = [],
    ) {
        $this->events = $events;
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDeletedId(): ?int
    {
        return $this->deletedId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return list<Event>
     */
    public function getEvents(): array
    {
        return $this->events;
    }
}
