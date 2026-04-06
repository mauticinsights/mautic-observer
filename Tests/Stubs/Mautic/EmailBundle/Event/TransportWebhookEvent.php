<?php

declare(strict_types=1);

namespace Mautic\EmailBundle\Event;

use Symfony\Component\HttpFoundation\Request;

/**
 * Test double for {@see TransportWebhookEvent}.
 */
final class TransportWebhookEvent
{
    public function __construct(
        private Request $request,
    ) {
    }

    public function getRequest(): Request
    {
        return $this->request;
    }
}
