<?php

declare(strict_types=1);

namespace Mautic\CampaignBundle\Event;

use Mautic\CampaignBundle\Entity\Campaign;
use Symfony\Contracts\EventDispatcher\Event;

final class CampaignEvent extends Event
{
    public function __construct(
        private Campaign $campaign,
        private bool $isNew = false,
    ) {
    }

    public function getCampaign(): Campaign
    {
        return $this->campaign;
    }

    public function isNew(): bool
    {
        return $this->isNew;
    }
}
