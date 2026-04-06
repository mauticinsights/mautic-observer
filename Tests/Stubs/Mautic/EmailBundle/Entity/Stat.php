<?php

declare(strict_types=1);

namespace Mautic\EmailBundle\Entity;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;

final class Stat
{
    public function __construct(
        private ?Email $email = null,
        private ?Lead $lead = null,
        private ?string $source = null,
        private ?int $sourceId = null,
        private ?LeadList $list = null,
    ) {
    }

    public function getEmail(): ?Email
    {
        return $this->email;
    }

    public function getLead(): ?Lead
    {
        return $this->lead;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function getSourceId(): ?int
    {
        return $this->sourceId;
    }

    public function getList(): ?LeadList
    {
        return $this->list;
    }
}
