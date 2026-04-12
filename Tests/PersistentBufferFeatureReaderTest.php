<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests;

use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticInsightsBundle\Buffer\PersistentBufferFeatureReader;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Service\InsightsPublishedIntegrationFinder;
use PHPUnit\Framework\TestCase;

final class PersistentBufferFeatureReaderTest extends TestCase
{
    public function testDisabledWhenIntegrationUnpublished(): void
    {
        $finder = InsightsPublishedIntegrationFinder::createForTest(
            new Integration(false, [], InsightsApiConfig::NAME, [])
        );
        $reader = new PersistentBufferFeatureReader($finder);

        $this->assertFalse($reader->isDurableBufferEnabled());
    }

    public function testEnabledWhenFeatureBlockMissing(): void
    {
        $integration = new Integration(true, [], InsightsApiConfig::NAME, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => 't',
        ]);
        $finder = InsightsPublishedIntegrationFinder::createForTest($integration);
        $reader = new PersistentBufferFeatureReader($finder);

        $this->assertTrue($reader->isDurableBufferEnabled());
    }

    public function testDisabledWhenPersistentBufferOff(): void
    {
        $integration = new Integration(true, [
            InsightsApiConfig::FEATURE_SETTINGS_INTEGRATION_SUBKEY => [
                InsightsApiConfig::FEATURE_PERSISTENT_EVENT_BUFFER => 0,
            ],
        ], InsightsApiConfig::NAME, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => 't',
        ]);
        $finder = InsightsPublishedIntegrationFinder::createForTest($integration);
        $reader = new PersistentBufferFeatureReader($finder);

        $this->assertFalse($reader->isDurableBufferEnabled());
    }

    public function testEnabledWhenPersistentBufferOn(): void
    {
        $integration = new Integration(true, [
            InsightsApiConfig::FEATURE_SETTINGS_INTEGRATION_SUBKEY => [
                InsightsApiConfig::FEATURE_PERSISTENT_EVENT_BUFFER => 1,
            ],
        ], InsightsApiConfig::NAME, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => 't',
        ]);
        $finder = InsightsPublishedIntegrationFinder::createForTest($integration);
        $reader = new PersistentBufferFeatureReader($finder);

        $this->assertTrue($reader->isDurableBufferEnabled());
    }
}
