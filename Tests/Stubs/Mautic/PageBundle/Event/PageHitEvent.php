<?php

declare(strict_types=1);

namespace Mautic\PageBundle\Event;

use Mautic\PageBundle\Entity\Hit;
use Mautic\PageBundle\Entity\Page;

class PageHitEvent
{
    public function __construct(
        private Hit $hit,
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
}
