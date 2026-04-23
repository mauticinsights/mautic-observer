<?php

declare(strict_types=1);

namespace Mautic\FormBundle\Entity;

use DateTimeInterface;
use Mautic\LeadBundle\Entity\Lead;

class Submission
{
    /**
     * @param array<string, mixed> $results
     */
    public function __construct(
        private ?Form $form = null,
        private ?Lead $lead = null,
        private int $id = 0,
        private array $results = [],
        private ?string $trackingId = null,
        private string $referer = '',
        private ?DateTimeInterface $dateSubmitted = null,
        private ?object $page = null,
    ) {
    }

    public function getForm(): ?Form
    {
        return $this->form;
    }

    public function getLead(): ?Lead
    {
        return $this->lead;
    }

    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function getResults(): array
    {
        return $this->results;
    }

    public function getTrackingId(): ?string
    {
        return $this->trackingId;
    }

    public function getReferer(): string
    {
        return $this->referer;
    }

    public function getDateSubmitted(): ?DateTimeInterface
    {
        return $this->dateSubmitted;
    }

    public function getPage(): ?object
    {
        return $this->page;
    }
}
