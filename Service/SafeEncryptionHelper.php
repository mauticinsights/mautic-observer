<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use Mautic\CoreBundle\Helper\EncryptionHelper;
use Throwable;

/**
 * Decorates core {@see EncryptionHelper} so IntegrationsBundle's final
 * {@see \Mautic\IntegrationsBundle\Facade\EncryptionService} can stay typed correctly
 * while empty / plain-text api_keys do not trigger PHP 8.2 TypeErrors.
 *
 * Mautic 7 made {@code EncryptionService} final, so the previous approach of replacing
 * that service with {@see SafeEncryptionService} is no longer valid.
 */
final class SafeEncryptionHelper extends EncryptionHelper
{
    public function __construct(
        private readonly EncryptionHelper $inner,
    ) {
        // Skip parent::__construct — all work is delegated to $inner.
    }

    public function encrypt($data): string
    {
        if (!\is_string($data) || '' === $data) {
            return \is_string($data) ? $data : '';
        }

        // Already stored in Mautic encrypted form — do not double-encrypt.
        if (str_contains($data, '|')) {
            return $data;
        }

        try {
            return $this->inner->encrypt($data);
        } catch (Throwable) {
            return $data;
        }
    }

    /**
     * @param bool $mainDecryptOnly
     *
     * @return mixed|false
     */
    public function decrypt($data, $mainDecryptOnly = false)
    {
        if (!\is_string($data) || '' === $data) {
            return false;
        }

        // Plain text (e.g. after saveIntegrationConfiguration restores decrypted keys).
        if (!str_contains($data, '|')) {
            return $data;
        }

        try {
            return $this->inner->decrypt($data, $mainDecryptOnly);
        } catch (Throwable) {
            return false;
        }
    }
}
