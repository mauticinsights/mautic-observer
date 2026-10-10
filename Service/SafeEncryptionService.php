<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use Mautic\CoreBundle\Helper\EncryptionHelper;
use Throwable;

/**
 * Array-level encrypt/decrypt guard used by standalone unit tests.
 *
 * Production wiring (Mautic 7+) decorates {@see EncryptionHelper} with
 * {@see SafeEncryptionHelper} because IntegrationsBundle's {@code EncryptionService}
 * is final and cannot be replaced in the container.
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
