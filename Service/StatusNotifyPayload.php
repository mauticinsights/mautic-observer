<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

/**
 * JSON body for POST /status on {@see \MauticPlugin\MauticInsightsBundle\InsightsApiConfig::STATUS_NOTIFY_URL}.
 *
 * sender-email = Mautic core parameter mailer_from_email (Email settings: "E-mail address to send mail from").
 */
final class StatusNotifyPayload
{
    /**
     * @return array{mautic-version: string, plugin-version: string, contact-count: int, sender-email: string}
     */
    public static function build(string $mauticVersion, string $pluginVersion, int $contactCount, string $senderEmail): array
    {
        return [
            'mautic-version' => $mauticVersion,
            'plugin-version' => $pluginVersion,
            'contact-count' => max(0, $contactCount),
            'sender-email' => $senderEmail,
        ];
    }
}
