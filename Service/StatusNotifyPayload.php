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
     * @return array{mautic-version: string, plugin-version: string, identified-contacts: int, anonymous-contacts: int, sender-email: string, plugin-enabled: bool}
     */
    public static function build(
        string $mauticVersion,
        string $pluginVersion,
        int $identifiedContacts,
        int $anonymousContacts,
        string $senderEmail,
        bool $pluginEnabled = true,
    ): array {
        return [
            'mautic-version' => $mauticVersion,
            'plugin-version' => $pluginVersion,
            'identified-contacts' => max(0, $identifiedContacts),
            'anonymous-contacts' => max(0, $anonymousContacts),
            'sender-email' => $senderEmail,
            'plugin-enabled' => $pluginEnabled,
        ];
    }
}
