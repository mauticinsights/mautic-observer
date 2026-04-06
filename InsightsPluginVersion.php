<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle;

use Throwable;

/**
 * Reads {@code version} from this bundle's {@code composer.json} (cached per request).
 */
final class InsightsPluginVersion
{
    public static function get(): string
    {
        static $cached = null;
        if (null !== $cached) {
            return $cached;
        }

        $path = __DIR__.'/composer.json';
        if (!is_readable($path)) {
            return $cached = 'unknown';
        }

        try {
            $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (!\is_array($json) || !isset($json['version'])) {
                return $cached = 'unknown';
            }

            return $cached = (string) $json['version'];
        } catch (Throwable) {
            return $cached = 'unknown';
        }
    }
}
