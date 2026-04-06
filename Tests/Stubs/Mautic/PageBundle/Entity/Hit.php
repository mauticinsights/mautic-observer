<?php

declare(strict_types=1);

namespace Mautic\PageBundle\Entity;

use Mautic\LeadBundle\Entity\Lead;

class Hit
{
    public function __construct(
        private ?Page $page = null,
        private ?Lead $lead = null,
    ) {
    }

    public function getPage(): ?Page
    {
        return $this->page;
    }

    public function getLead(): ?Lead
    {
        return $this->lead;
    }
}
