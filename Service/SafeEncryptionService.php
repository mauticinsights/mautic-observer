<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use Mautic\IntegrationsBundle\Facade\EncryptionService;
use Throwable;

/**
 * Guards Mautic's IntegrationsBundle EncryptionService against edge-case values.
 *
 * On fresh plugin installs Mautic seeds api_keys with empty strings
 * (e.g. {'http_token': ''}). After IntegrationsHelper::saveIntegrationConfiguration()
 * the entity's api_keys are restored to plain text. In both cases calling
 * EncryptionHelper::decrypt() emits a PHP Warning (Undefined array key 1 from
 * the '|' split) and on PHP 8.2 throws a TypeError from base64_decode(null),
 * which propagates as HTTP 500 from the integration config save controller.
 *
 * This subclass is registered via services.php to override EncryptionService::class
 * so ALL code paths in IntegrationsBundle are covered, not just our own subscribers.
 */
final class SafeEncryptionService extends EncryptionService
{
    /**
     * @param mixed $keys
     *
     * @return array|string|false
     */
    public function decrypt($keys, $onlyPrimaryCipher = false)
    {
        if (null === $keys) {
            return [];
        }

        if (!is_array($keys)) {
            return $this->safeDecryptSingle($keys, (bool) $onlyPrimaryCipher);
        }

        $result = [];
        foreach ($keys as $name => $key) {
            if (!\is_string($key) || '' === $key) {
                // Empty/non-string values cannot be encrypted — pass through as-is.
                $result[$name] = $key;
                continue;
            }
            $result[$name] = $this->safeDecryptSingle($key, (bool) $onlyPrimaryCipher);
        }

        return $result;
    }

    /**
     * @param mixed $key
     *
     * @return string|false
     */
    private function safeDecryptSingle($key, bool $onlyPrimaryCipher)
    {
        if (!\is_string($key) || '' === $key) {
            return false;
        }

        // Mautic encrypts as base64(cipher)|base64(iv). A value without '|' is
        // either plain text (restored after save) or not yet encrypted.
        // Passing it to EncryptionHelper::decrypt() would emit a PHP Warning and
        // throw TypeError on PHP 8.2 — return false (not encrypted) instead.
        if (!str_contains($key, '|')) {
            return false;
        }

        try {
            // parent::decrypt() calls $this->encryptionHelper->decrypt() which
            // is in EncryptionService's private scope and is accessible from here.
            return parent::decrypt($key, $onlyPrimaryCipher);
        } catch (Throwable) {
            return false;
        }
    }
}
