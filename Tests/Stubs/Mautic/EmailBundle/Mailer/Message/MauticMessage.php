<?php

declare(strict_types=1);

namespace Mautic\EmailBundle\Mailer\Message;

/**
 * Minimal test double for {@see MauticMessage}.
 */
final class MauticMessage
{
    /**
     * @param list<object> $from
     * @param list<object> $to
     */
    public function __construct(
        private string $subject = '',
        private ?string $leadIdHash = null,
        private array $from = [],
        private array $to = [],
    ) {
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getLeadIdHash(): ?string
    {
        return $this->leadIdHash;
    }

    /**
     * @return list<object>
     */
    public function getFrom(): array
    {
        return $this->from;
    }

    /**
     * @return list<object>
     */
    public function getTo(): array
    {
        return $this->to;
    }
}
