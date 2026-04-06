<?php

declare(strict_types=1);

namespace Mautic\PointBundle\Event;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\PointBundle\Entity\TriggerEvent as TriggerEventEntity;

/**
 * Test double for {@see TriggerExecutedEvent}.
 */
final class TriggerExecutedEvent
{
    public function __construct(
        private TriggerEventEntity $triggerEvent,
        private Lead $lead,
    ) {
    }

    public function getTriggerEvent(): TriggerEventEntity
    {
        return $this->triggerEvent;
    }

    public function getLead(): Lead
    {
        return $this->lead;
    }
}
