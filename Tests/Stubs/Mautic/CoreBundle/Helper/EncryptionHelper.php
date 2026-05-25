<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Helper;

/**
 * Test double for {@see EncryptionHelper}.
 * Skips constructor dependencies; individual tests configure behaviour via subclass or mock.
 */
class EncryptionHelper
{
    public function encrypt(mixed $data): string
    {
        return (string) $data;
    }

    /**
     * @return mixed|false
     */
    public function decrypt(mixed $key, bool $mainDecryptOnly = false)
    {
        return $key;
    }
}
