<?php

declare(strict_types=1);

namespace Mautic\CampaignBundle\Event;

use Mautic\CampaignBundle\Entity\LeadEventLog;
use Symfony\Contracts\EventDispatcher\Event;

final class ExecutedEvent extends Event
{
    public function __construct(
        private LeadEventLog $log,
    ) {
    }

    public function getLog(): LeadEventLog
    {
        return $this->log;
    }
}
