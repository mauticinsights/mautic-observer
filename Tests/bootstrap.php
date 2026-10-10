<?php

declare(strict_types=1);

$pluginRoot = dirname(__DIR__);
$mauticVendor = dirname($pluginRoot, 2).'/vendor/autoload.php';
$pluginVendor = $pluginRoot.'/vendor/autoload.php';

if (is_file($pluginVendor)) {
    require $pluginVendor;
} elseif (is_file($mauticVendor)) {
    require $mauticVendor;

    // Prefer plugin stub doubles over real Mautic classes for standalone-style unit tests.
    $stubLoader = new Composer\Autoload\ClassLoader();
    $stubLoader->addPsr4('Mautic\\', $pluginRoot.'/Tests/Stubs/Mautic/');
    $stubLoader->register(true);
} else {
    throw new RuntimeException('Composer autoload not found (plugin vendor/ or Mautic root vendor/).');
}

// Bundled for monorepo runs (root vendor has no dg/bypass-finals).
$bypassFinalsAutoload = $pluginRoot.'/Tests/lib/bypass-finals/src/BypassFinals.php';
if (!class_exists(DG\BypassFinals::class, false) && is_file($bypassFinalsAutoload)) {
    require $pluginRoot.'/Tests/lib/bypass-finals/src/NativeWrapper.php';
    require $pluginRoot.'/Tests/lib/bypass-finals/src/MutatingWrapper.php';
    require $bypassFinalsAutoload;
}

// Allow PHPUnit to mock final plugin services without mutating PHPUnit/Symfony readonly.
if (class_exists(DG\BypassFinals::class)) {
    DG\BypassFinals::allowPaths([
        $pluginRoot.'/Service/*',
        $pluginRoot.'/Buffer/*',
    ]);
    DG\BypassFinals::enable(bypassReadOnly: false);
}
