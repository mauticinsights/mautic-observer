<?php

declare(strict_types=1);

namespace Mautic\EmailBundle\Event;

use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\Stat;

final class EmailOpenEvent
{
    public function __construct(
        private Stat $stat,
        private bool $firstTime = false,
    ) {
    }

    public function getEmail(): ?Email
    {
        return $this->stat->getEmail();
    }

    public function getStat(): Stat
    {
        return $this->stat;
    }

    public function isFirstTime(): bool
    {
        return $this->firstTime;
    }
}
