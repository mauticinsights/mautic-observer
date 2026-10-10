<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests;

use MauticPlugin\MauticInsightsBundle\InsightsPluginVersion;
use PHPUnit\Framework\TestCase;

final class InsightsPluginVersionTest extends TestCase
{
    public function testReadsVersionFromComposerJson(): void
    {
        $this->assertSame('7.0.3', InsightsPluginVersion::get());
    }
}
