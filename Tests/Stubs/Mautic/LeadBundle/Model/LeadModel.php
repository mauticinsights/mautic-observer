<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\Model;

use Mautic\LeadBundle\Entity\Lead;

/**
 * Test double — set {@see $listRows} for {@see getLists()} output.
 */
class LeadModel
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $listRows = [];

    /**
     * @return list<array<string, mixed>>
     */
    public function getLists(
        Lead $lead,
        bool $forLists = false,
        bool $arrayHydration = false,
        bool $isPublic = false,
        bool $isPreferenceCenter = false,
    ): array {
        return $this->listRows;
    }
}
