<?php

declare(strict_types=1);

namespace Mautic\EmailBundle\Event;

use Mautic\EmailBundle\Entity\Email;
use Mautic\LeadBundle\Entity\Lead;

/**
 * Test double — the test sets public properties.
 */
class EmailSendEvent
{
    public bool $internalSend = false;

    /** @var Lead|array<string, mixed>|null */
    public $lead;

    public ?Email $email = null;

    /** @var array<int, mixed> */
    public array $source = [];

    public function isInternalSend(): bool
    {
        return $this->internalSend;
    }

    /**
     * @return Lead|array<string, mixed>|null
     */
    public function getLead()
    {
        return $this->lead;
    }

    public function getEmail(): ?Email
    {
        return $this->email;
    }

    /**
     * @return array<int, mixed>
     */
    public function getSource(): array
    {
        return $this->source;
    }
}
