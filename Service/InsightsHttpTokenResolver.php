<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use Mautic\CoreBundle\Helper\EncryptionHelper;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use Throwable;

/**
 * Reads the outbound Bearer token from {@see Integration::getApiKeys()} {@see InsightsApiConfig::HTTP_TOKEN_FIELD}.
 *
 * Mautic stores API keys encrypted; decryption matches {@see \Mautic\PluginBundle\Integration\AbstractIntegration::decryptApiKeys()}
 * via {@see EncryptionHelper} (same loop as core {@see \Mautic\PluginBundle\Integration\AbstractIntegration::decryptApiKeys()}).
 */
final class InsightsHttpTokenResolver
{
    public function __construct(
        private ?EncryptionHelper $encryptionHelper = null,
    ) {
    }

    public function resolve(Integration $integration): string
    {
        $raw = $integration->getApiKeys();
        if (!\is_array($raw)) {
            return '';
        }

        $apiKeys = null !== $this->encryptionHelper
            ? $this->decryptApiKeys($this->encryptionHelper, $raw)
            : $raw;

        if (!\is_array($apiKeys)) {
            return '';
        }

        return \trim((string) ($apiKeys[InsightsApiConfig::HTTP_TOKEN_FIELD] ?? ''));
    }

    /**
     * @param array<string, mixed> $keys
     *
     * @return array<string, mixed>
     */
    private function decryptApiKeys(EncryptionHelper $helper, array $keys): array
    {
        $decrypted = [];

        foreach ($keys as $name => $key) {
            // Skip non-string or empty values — EncryptionHelper::decrypt('') triggers a PHP Warning
            // (Undefined array key 1) and in PHP 8.2 throws TypeError via openssl_decrypt(null, ...).
            if (!\is_string($key) || '' === $key) {
                $decrypted[$name] = $key;
                continue;
            }

            // Mautic's IntegrationsHelper::saveIntegrationConfiguration() restores plain-text keys onto the entity
            // after saving encrypted keys to DB, so at INTEGRATION_CONFIG_AFTER_SAVE the value is already plain.
            // Try to decrypt; if it fails (plain text) use as-is.
            try {
                $plain = $helper->decrypt($key, false);
                $decrypted[$name] = (false !== $plain) ? $plain : $key;
            } catch (Throwable) {
                $decrypted[$name] = $key;
            }
        }

        return $decrypted;
    }
}
