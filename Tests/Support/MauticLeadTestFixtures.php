<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\Support;

use Mautic\LeadBundle\Entity\Lead;

trait MauticLeadTestFixtures
{
    private function makeLead(int $id, ?string $email = null): Lead
    {
        $lead = new Lead();
        $lead->stubId = $id;
        $lead->stubEmail = $email;

        return $lead;
    }
}
