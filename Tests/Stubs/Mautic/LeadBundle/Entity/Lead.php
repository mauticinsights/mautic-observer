<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\Entity;

use DateTimeInterface;

/**
 * Test double for subscriber / builder tests (no mautic/core-lib).
 */
class Lead
{
    public ?DateTimeInterface $dateIdentified = null;

    public ?int $stubId = null;

    public ?int $stubDeletedId = null;

    /** Mirrors {@see \Mautic\CoreBundle\Entity\FormEntity::$deletedId} after repository delete. */
    public ?int $deletedId = null;

    public bool $anonymous = false;

    public ?string $stubEmail = null;

    public ?string $stubPrimaryIdentifier = null;

    public ?string $stubCompany = null;

    public ?string $stubFirstname = null;

    public ?string $stubLastname = null;

    /** @var iterable<object> */
    public iterable $stubTags = [];

    /**
     * Null here = field unset in the test fixture → builder skips the `points` property.
     * In real Mautic {@see \Mautic\LeadBundle\Entity\Lead::$points} is always `int` (default 0),
     * so production payloads always include `points`. Tests that care about propagation set this explicitly.
     */
    public ?int $stubPoints = null;

    public function getId(): ?int
    {
        return $this->stubId;
    }

    public function getDeletedId(): ?int
    {
        return $this->stubDeletedId;
    }

    public function isAnonymous(): bool
    {
        return $this->anonymous;
    }

    public function getEmail(): ?string
    {
        return $this->stubEmail;
    }

    /**
     * @param bool $lastFirst
     */
    public function getPrimaryIdentifier($lastFirst = false): ?string
    {
        return $this->stubPrimaryIdentifier;
    }

    public function getCompany(): ?string
    {
        return $this->stubCompany;
    }

    public function getFirstname(): ?string
    {
        return $this->stubFirstname;
    }

    public function getLastname(): ?string
    {
        return $this->stubLastname;
    }

    /**
     * @return iterable<object>
     */
    public function getTags(): iterable
    {
        return $this->stubTags;
    }

    public function getPoints(): ?int
    {
        return $this->stubPoints;
    }
}
