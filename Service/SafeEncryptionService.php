<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use Mautic\CoreBundle\Helper\EncryptionHelper;
use Throwable;

/**
 * Guards Mautic's IntegrationsBundle EncryptionService against edge-case values.
 *
 * On fresh plugin installs Mautic seeds api_keys with empty strings
 * (e.g. {'http_token': ''}). After IntegrationsHelper::saveIntegrationConfiguration()
 * the entity's api_keys are also temporarily restored to plain text. In both
 * cases calling EncryptionHelper::decrypt() emits a PHP Warning (Undefined
 * array key 1 from the '|' split) and on PHP 8.2 throws a TypeError from
 * base64_decode(null), which propagates as HTTP 500 from the integration config
 * save controller.
 *
 * This class replicates the encrypt/decrypt interface of EncryptionService
 * (without extending it, to keep the plugin self-contained and satisfy PHPStan)
 * and is registered via services.php as EncryptionService::class so all code
 * paths in IntegrationsBundle are covered.
 */
final class SafeEncryptionService
{
    public function __construct(
        private EncryptionHelper $encryptionHelper,
    ) {
    }

    /**
     * @return array<string, mixed>|string
     */
    public function encrypt(mixed $keys): array|string
    {
        if (!is_array($keys)) {
            return $this->encryptionHelper->encrypt($keys);
        }

        foreach ($keys as $name => $key) {
            $keys[$name] = $this->encryptionHelper->encrypt($key);
        }

        return $keys;
    }

    /**
     * @return array<string, mixed>|string|false
     */
    public function decrypt(mixed $keys, bool $onlyPrimaryCipher = false): array|string|false
    {
        if (null === $keys) {
            return [];
        }

        if (!is_array($keys)) {
            return $this->safeDecryptSingle((string) $keys, $onlyPrimaryCipher);
        }

        $result = [];
        foreach ($keys as $name => $key) {
            if (!\is_string($key) || '' === $key) {
                // Empty / non-string values are not encrypted — pass through as-is.
                $result[$name] = $key;
                continue;
            }
            $result[$name] = $this->safeDecryptSingle($key, $onlyPrimaryCipher);
        }

        return $result;
    }

    private function safeDecryptSingle(string $key, bool $onlyPrimaryCipher): string|false
    {
        if ('' === $key) {
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
            return $this->encryptionHelper->decrypt($key, $onlyPrimaryCipher);
        } catch (Throwable) {
            return false;
        }
    }
}
