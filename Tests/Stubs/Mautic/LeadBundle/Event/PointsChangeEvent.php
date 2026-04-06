<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\Event;

use Mautic\LeadBundle\Entity\Lead;

class PointsChangeEvent
{
    public function __construct(
        private Lead $lead,
        private int $oldPoints,
        private int $newPoints,
    ) {
    }

    public function getLead(): Lead
    {
        return $this->lead;
    }

    public function getOldPoints(): int
    {
        return $this->oldPoints;
    }

    public function getNewPoints(): int
    {
        return $this->newPoints;
    }
}
