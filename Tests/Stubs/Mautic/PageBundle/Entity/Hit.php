<?php

declare(strict_types=1);

namespace Mautic\PageBundle\Entity;

use DateTimeInterface;
use Mautic\EmailBundle\Entity\Email;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadDevice;

/**
 * Test double for Page hit subscriber / payload builder tests (no mautic/core-lib).
 *
 * Fields are public + nullable on purpose so tests can tweak the parts they care about
 * without constructor-arg churn; accessor methods mirror the real Mautic Hit entity.
 */
class Hit
{
    public ?int $stubId = null;

    public ?int $stubCode = null;

    public ?string $stubTrackingId = null;

    public ?DateTimeInterface $stubDateHit = null;

    public ?string $stubUrl = null;

    public ?string $stubUrlTitle = null;

    public ?string $stubReferer = null;

    public ?string $stubPageLanguage = null;

    public ?string $stubCountry = null;

    public ?string $stubRegion = null;

    public ?string $stubCity = null;

    public ?string $stubIsp = null;

    public ?string $stubOrganization = null;

    public ?string $stubSource = null;

    public ?int $stubSourceId = null;

    /** @var array<string, mixed> */
    public array $stubQuery = [];

    /** @var list<string> */
    public array $stubBrowserLanguages = [];

    public ?Email $stubEmail = null;

    public ?Redirect $stubRedirect = null;

    public ?LeadDevice $stubDevice = null;

    public function __construct(
        private ?Page $page = null,
        private ?Lead $lead = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->stubId;
    }

    public function getCode(): ?int
    {
        return $this->stubCode;
    }

    public function getPage(): ?Page
    {
        return $this->page;
    }

    public function getLead(): ?Lead
    {
        return $this->lead;
    }

    public function getTrackingId(): ?string
    {
        return $this->stubTrackingId;
    }

    public function getDateHit(): ?DateTimeInterface
    {
        return $this->stubDateHit;
    }

    public function getUrl(): ?string
    {
        return $this->stubUrl;
    }

    public function getUrlTitle(): ?string
    {
        return $this->stubUrlTitle;
    }

    public function getReferer(): ?string
    {
        return $this->stubReferer;
    }

    public function getPageLanguage(): ?string
    {
        return $this->stubPageLanguage;
    }

    public function getCountry(): ?string
    {
        return $this->stubCountry;
    }

    public function getRegion(): ?string
    {
        return $this->stubRegion;
    }

    public function getCity(): ?string
    {
        return $this->stubCity;
    }

    public function getIsp(): ?string
    {
        return $this->stubIsp;
    }

    public function getOrganization(): ?string
    {
        return $this->stubOrganization;
    }

    public function getSource(): ?string
    {
        return $this->stubSource;
    }

    public function getSourceId(): ?int
    {
        return $this->stubSourceId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getQuery(): array
    {
        return $this->stubQuery;
    }

    /**
     * @return list<string>
     */
    public function getBrowserLanguages(): array
    {
        return $this->stubBrowserLanguages;
    }

    public function getEmail(): ?Email
    {
        return $this->stubEmail;
    }

    public function getRedirect(): ?Redirect
    {
        return $this->stubRedirect;
    }

    public function getDeviceStat(): ?LeadDevice
    {
        return $this->stubDevice;
    }
}
