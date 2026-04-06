<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\Event;

use Mautic\LeadBundle\Entity\Lead;

class LeadEvent
{
    public function __construct(
        private Lead $lead,
        private bool $isNew = false,
    ) {
    }

    public function isNew(): bool
    {
        return $this->isNew;
    }

    public function getLead(): Lead
    {
        return $this->lead;
    }
}
