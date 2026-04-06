<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\Event;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use Symfony\Contracts\EventDispatcher\Event;

class ListChangeEvent extends Event
{
    private ?Lead $lead = null;

    /** @var list<Lead>|null */
    private ?array $leads = null;

    /**
     * @param Lead|list<Lead> $leadsOrLead
     */
    public function __construct(
        Lead|array $leadsOrLead,
        private LeadList $list,
        private bool $added = true,
    ) {
        if (\is_array($leadsOrLead)) {
            $this->leads = $leadsOrLead;
        } else {
            $this->lead = $leadsOrLead;
        }
    }

    public function getLead(): ?Lead
    {
        return $this->lead;
    }

    /**
     * @return list<Lead>|null
     */
    public function getLeads(): ?array
    {
        return $this->leads;
    }

    public function getList(): LeadList
    {
        return $this->list;
    }

    public function wasAdded(): bool
    {
        return $this->added;
    }

    public function wasRemoved(): bool
    {
        return !$this->added;
    }
}
