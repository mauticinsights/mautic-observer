<?php

declare(strict_types=1);

namespace Mautic\EmailBundle\Event;

use Mautic\EmailBundle\Entity\Email;

/**
 * Test double for {@see EmailEvent} (save/delete lifecycle).
 */
final class EmailEvent
{
    public function __construct(
        private Email $email,
        private bool $isNew = false,
    ) {
    }

    public function getEmail(): Email
    {
        return $this->email;
    }

    public function isNew(): bool
    {
        return $this->isNew;
    }
}
