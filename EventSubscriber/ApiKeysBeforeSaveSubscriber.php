<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\EventSubscriber;

use Mautic\IntegrationsBundle\Event\KeysSaveEvent;
use Mautic\IntegrationsBundle\IntegrationEvents;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Preserves the HTTP token when the admin saves plugin config without changing it.
 *
 * The auth field uses {@see \Symfony\Component\Form\Extension\Core\Type\PasswordType} (empty on
 * submit when unchanged). {@see \Mautic\IntegrationsBundle\Helper\IntegrationsHelper} then encrypts
 * api keys — an empty submitted value would overwrite the stored token unless we restore the
 * previous key from {@see KeysSaveEvent::getOldKeys()}.
 */
final class ApiKeysBeforeSaveSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            IntegrationEvents::INTEGRATION_API_KEYS_BEFORE_SAVE => ['onApiKeysBeforeSave', 0],
        ];
    }

    public function onApiKeysBeforeSave(KeysSaveEvent $event): void
    {
        if (InsightsApiConfig::NAME !== $event->getIntegrationConfiguration()->getName()) {
            return;
        }

        $field = InsightsApiConfig::HTTP_TOKEN_FIELD;
        $newKeys = $event->getIntegrationConfiguration()->getApiKeys();

        if (!\is_array($newKeys)) {
            return;
        }

        if ('' !== \trim((string) ($newKeys[$field] ?? ''))) {
            return;
        }

        $oldKeys = $event->getOldKeys();
        $previous = \trim((string) ($oldKeys[$field] ?? ''));

        if ('' === $previous) {
            return;
        }

        $newKeys[$field] = $oldKeys[$field];
        $event->getIntegrationConfiguration()->setApiKeys($newKeys);
    }
}
