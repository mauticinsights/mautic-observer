<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Helper;

final class AppVersion
{
    public function __construct(
        private string $version = '5.0.0-test',
    ) {
    }

    public function getVersion(): string
    {
        return $this->version;
    }
}
