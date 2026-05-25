<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

// Allow PHPUnit to mock final service classes (e.g. BulkSyncService) without changing production code.
DG\BypassFinals::enable();
