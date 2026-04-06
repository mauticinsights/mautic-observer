<?php

declare(strict_types=1);

namespace Mautic\CampaignBundle\Event;

use Mautic\CampaignBundle\Entity\LeadEventLog;
use Symfony\Contracts\EventDispatcher\Event;

final class ScheduledEvent extends Event
{
    public function __construct(
        private LeadEventLog $log,
        private bool $isReschedule = false,
    ) {
    }

    public function getLog(): LeadEventLog
    {
        return $this->log;
    }

    public function isReschedule(): bool
    {
        return $this->isReschedule;
    }
}
