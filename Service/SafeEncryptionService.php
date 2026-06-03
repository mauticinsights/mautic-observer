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
            return $this->safeEncryptSingle($keys);
        }

        $result = [];
        foreach ($keys as $name => $key) {
            $result[$name] = $this->safeEncryptSingle($key);
        }

        return $result;
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

    private function safeEncryptSingle(mixed $key): mixed
    {
        if (!\is_string($key) || '' === $key) {
            return $key;
        }

        // Already stored in Mautic encrypted form — do not double-encrypt.
        if (str_contains($key, '|')) {
            return $key;
        }

        try {
            return $this->encryptionHelper->encrypt($key);
        } catch (Throwable) {
            return $key;
        }
    }

    private function safeDecryptSingle(string $key, bool $onlyPrimaryCipher): string|false
    {
        if ('' === $key) {
            return false;
        }

        // Plain text (e.g. after saveIntegrationConfiguration restores decrypted keys).
        // Core EncryptionHelper::decrypt() would warn on missing '|' segment.
        if (!str_contains($key, '|')) {
            return $key;
        }

        try {
            $plain = $this->encryptionHelper->decrypt($key, $onlyPrimaryCipher);

            return (false !== $plain) ? (string) $plain : false;
        } catch (Throwable) {
            return false;
        }
    }
}
