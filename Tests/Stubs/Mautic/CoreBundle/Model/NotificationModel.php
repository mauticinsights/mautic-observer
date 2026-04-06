<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Model;

use DateTime;

/**
 * Test double for {@see NotificationModel}.
 */
class NotificationModel
{
    /** @var list<array<string, mixed>> */
    public array $added = [];

    public function addNotification(
        mixed $message,
        mixed $type = null,
        mixed $isRead = false,
        mixed $header = null,
        mixed $iconClass = null,
        ?DateTime $datetime = null,
        mixed $user = null,
        ?string $deduplicateValue = null,
        ?DateTime $deduplicateDateTimeFrom = null,
    ): void {
        $this->added[] = [
            'message' => $message,
            'user' => $user,
            'dedupe' => $deduplicateValue,
        ];
    }
}
