<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\Event;

use Mautic\LeadBundle\Entity\LeadList;
use Symfony\Contracts\EventDispatcher\Event;

final class LeadListEvent extends Event
{
    public function __construct(
        private LeadList $list,
        private bool $isNew = false,
    ) {
    }

    public function getList(): LeadList
    {
        return $this->list;
    }

    public function isNew(): bool
    {
        return $this->isNew;
    }
}
