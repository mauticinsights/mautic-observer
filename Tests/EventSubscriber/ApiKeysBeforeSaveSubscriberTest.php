<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\EventSubscriber;

use Mautic\IntegrationsBundle\Event\KeysSaveEvent;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticInsightsBundle\EventSubscriber\ApiKeysBeforeSaveSubscriber;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use PHPUnit\Framework\TestCase;

final class ApiKeysBeforeSaveSubscriberTest extends TestCase
{
    public function testRestoresTokenWhenSubmittedValueIsEmpty(): void
    {
        $integration = new Integration(true, [], InsightsApiConfig::NAME, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => '',
        ]);

        $subscriber = new ApiKeysBeforeSaveSubscriber();
        $subscriber->onApiKeysBeforeSave(new KeysSaveEvent($integration, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => 'stored-token',
        ]));

        $this->assertSame(
            ['http_token' => 'stored-token'],
            $integration->getApiKeys(),
        );
    }

    public function testKeepsNewTokenWhenSubmittedValueIsNonEmpty(): void
    {
        $integration = new Integration(true, [], InsightsApiConfig::NAME, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => 'new-token',
        ]);

        $subscriber = new ApiKeysBeforeSaveSubscriber();
        $subscriber->onApiKeysBeforeSave(new KeysSaveEvent($integration, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => 'old-token',
        ]));

        $this->assertSame('new-token', $integration->getApiKeys()[InsightsApiConfig::HTTP_TOKEN_FIELD]);
    }

    public function testIgnoresOtherIntegrations(): void
    {
        $integration = new Integration(true, [], 'OtherPlugin', [
            'http_token' => '',
        ]);

        $subscriber = new ApiKeysBeforeSaveSubscriber();
        $subscriber->onApiKeysBeforeSave(new KeysSaveEvent($integration, [
            'http_token' => 'stored-token',
        ]));

        $this->assertSame('', $integration->getApiKeys()['http_token']);
    }

    public function testDoesNotRestoreWhenOldTokenWasAlsoEmpty(): void
    {
        $integration = new Integration(true, [], InsightsApiConfig::NAME, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => '',
        ]);

        $subscriber = new ApiKeysBeforeSaveSubscriber();
        $subscriber->onApiKeysBeforeSave(new KeysSaveEvent($integration, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => '',
        ]));

        $this->assertSame('', $integration->getApiKeys()[InsightsApiConfig::HTTP_TOKEN_FIELD]);
    }
}
