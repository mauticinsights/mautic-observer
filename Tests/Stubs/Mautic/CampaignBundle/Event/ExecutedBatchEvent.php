<?php

declare(strict_types=1);

namespace Mautic\CampaignBundle\Event;

use Mautic\CampaignBundle\Entity\LeadEventLog;
use Symfony\Contracts\EventDispatcher\Event;

final class ExecutedBatchEvent extends Event
{
    /**
     * @param list<LeadEventLog> $logs
     */
    public function __construct(
        private array $logs,
    ) {
    }

    /**
     * @return list<LeadEventLog>
     */
    public function getExecuted(): array
    {
        return $this->logs;
    }
}
