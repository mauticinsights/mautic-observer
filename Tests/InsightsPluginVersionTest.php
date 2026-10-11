<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests;

use MauticPlugin\MauticInsightsBundle\InsightsPluginVersion;
use PHPUnit\Framework\TestCase;

final class InsightsPluginVersionTest extends TestCase
{
    public function testReadsVersionFromComposerJson(): void
    {
        $composerPath = dirname(__DIR__).'/composer.json';
        $json = json_decode((string) file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);
        $expected = $json['version'] ?? null;

        $this->assertIsString($expected);
        $this->assertSame($expected, InsightsPluginVersion::get());
    }
}
