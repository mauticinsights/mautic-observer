<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\Event;

use Mautic\LeadBundle\Entity\Lead;
use Symfony\Contracts\EventDispatcher\Event;

final class LeadMergeEvent extends Event
{
    public function __construct(
        private Lead $victor,
        private Lead $loser
    ) {
    }

    public function getVictor(): Lead
    {
        return $this->victor;
    }

    public function getLoser(): Lead
    {
        return $this->loser;
    }
}
