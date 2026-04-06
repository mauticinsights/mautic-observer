<?php

declare(strict_types=1);

namespace Mautic\CampaignBundle\Event;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\LeadBundle\Entity\Lead;
use Symfony\Contracts\EventDispatcher\Event;

final class CampaignLeadChangeEvent extends Event
{
    /**
     * @param list<Lead> $batchLeads non-empty = batch; empty = use {@see $singleLead}
     */
    public function __construct(
        private Campaign $campaign,
        private string $action,
        private ?Lead $singleLead = null,
        private array $batchLeads = [],
    ) {
    }

    public function getCampaign(): Campaign
    {
        return $this->campaign;
    }

    public function getLead(): ?Lead
    {
        return $this->singleLead;
    }

    /**
     * @return list<Lead>
     */
    public function getLeads(): array
    {
        return $this->batchLeads;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }
}
