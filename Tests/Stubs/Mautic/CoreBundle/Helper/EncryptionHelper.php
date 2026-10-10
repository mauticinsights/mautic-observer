<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Helper;

/**
 * Test double for {@see EncryptionHelper}.
 * Skips constructor dependencies; individual tests configure behaviour via subclass or mock.
 * Signature mirrors Mautic core (untyped params) so {@see \MauticPlugin\MauticInsightsBundle\Service\SafeEncryptionHelper} stays compatible.
 */
class EncryptionHelper
{
    public function encrypt($data): string
    {
        return (string) $data;
    }

    /**
     * @param bool $mainDecryptOnly
     *
     * @return mixed|false
     */
    public function decrypt($key, $mainDecryptOnly = false)
    {
        return $key;
    }
}
