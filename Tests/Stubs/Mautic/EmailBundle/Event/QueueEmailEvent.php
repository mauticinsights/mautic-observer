<?php

declare(strict_types=1);

namespace Mautic\EmailBundle\Event;

use Mautic\EmailBundle\Mailer\Message\MauticMessage;

/**
 * Test double for {@see QueueEmailEvent}.
 */
final class QueueEmailEvent
{
    public function __construct(
        private MauticMessage $message,
    ) {
    }

    public function getMessage(): MauticMessage
    {
        return $this->message;
    }
}
