<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

class MaintenanceEvent extends Event
{
    private int $daysOld;

    private bool $dryRun;

    private bool $gdpr;

    public function __construct(int $daysOld, bool $dryRun, bool $gdpr)
    {
        $this->daysOld = $daysOld;
        $this->dryRun = $dryRun;
        $this->gdpr = $gdpr;
    }

    public function getDays(): int
    {
        return $this->daysOld;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function isGdpr(): bool
    {
        return $this->gdpr;
    }
}
