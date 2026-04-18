<?php

declare(strict_types=1);

namespace Mautic\PageBundle\Event;

use Mautic\PageBundle\Entity\Hit;
use Mautic\PageBundle\Entity\Page;

class PageHitEvent
{
    /**
     * @param array<string, mixed> $clickthroughData
     */
    public function __construct(
        private Hit $hit,
        private ?int $code = null,
        private array $clickthroughData = [],
        private bool $unique = false,
    ) {
    }

    public function getHit(): Hit
    {
        return $this->hit;
    }

    public function getPage(): ?Page
    {
        return $this->hit->getPage();
    }

    public function getCode(): ?int
    {
        return $this->code;
    }

    /**
     * @return array<string, mixed>
     */
    public function getClickthroughData(): array
    {
        return $this->clickthroughData;
    }

    public function isUnique(): bool
    {
        return $this->unique;
    }
}
