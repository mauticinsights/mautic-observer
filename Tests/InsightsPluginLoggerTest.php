<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests;

use MauticPlugin\MauticInsightsBundle\Service\InsightsPluginLogger;
use MauticPlugin\MauticInsightsBundle\Service\InsightsPluginLoggingFeatureReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class InsightsPluginLoggerTest extends TestCase
{
    public function testDoesNotForwardWhenLoggingDisabled(): void
    {
        $inner = $this->createMock(LoggerInterface::class);
        $inner->expects($this->never())->method('warning');

        $reader = $this->createMock(InsightsPluginLoggingFeatureReader::class);
        $reader->method('isPluginLoggingEnabled')->willReturn(false);

        (new InsightsPluginLogger($inner, $reader))->warning('x');
    }

    public function testForwardsWhenLoggingEnabled(): void
    {
        $inner = $this->createMock(LoggerInterface::class);
        $inner->expects($this->once())->method('warning')->with('x', []);

        $reader = $this->createMock(InsightsPluginLoggingFeatureReader::class);
        $reader->method('isPluginLoggingEnabled')->willReturn(true);

        (new InsightsPluginLogger($inner, $reader))->warning('x');
    }
}
