<?php

declare(strict_types=1);

namespace Mautic\EmailBundle\Event;

use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\Stat;

/**
 * Standalone-test stub matching Mautic 7 {@see EmailReplyEvent}.
 */
final class EmailReplyEvent
{
    public function __construct(
        private Stat $stat,
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
}
