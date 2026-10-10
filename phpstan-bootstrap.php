<?php

declare(strict_types=1);

/**
 * Bootstrap for monorepo PHPStan ({@code ../../bin/phpstan}).
 * Mautic classes come from the root vendor autoload — do not load Tests/Stubs here.
 */
if (!defined('MAUTIC_ENV')) {
    define('MAUTIC_ENV', '');
}
if (!defined('MAUTIC_VERSION')) {
    define('MAUTIC_VERSION', '');
}
if (!defined('MAUTIC_TABLE_PREFIX')) {
    define('MAUTIC_TABLE_PREFIX', '');
}
