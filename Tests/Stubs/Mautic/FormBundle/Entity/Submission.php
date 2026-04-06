<?php

declare(strict_types=1);

namespace Mautic\FormBundle\Entity;

use Mautic\LeadBundle\Entity\Lead;

class Submission
{
    public function __construct(
        private ?Form $form = null,
        private ?Lead $lead = null,
    ) {
    }

    public function getForm(): ?Form
    {
        return $this->form;
    }

    public function getLead(): ?Lead
    {
        return $this->lead;
    }
}
