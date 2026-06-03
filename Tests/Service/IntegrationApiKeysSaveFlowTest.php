<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\Service;

use Mautic\CoreBundle\Helper\EncryptionHelper;
use Mautic\IntegrationsBundle\Event\KeysSaveEvent;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticInsightsBundle\EventSubscriber\ApiKeysBeforeSaveSubscriber;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Service\InsightsHttpTokenResolver;
use MauticPlugin\MauticInsightsBundle\Service\SafeEncryptionService;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end save flow for a newly entered HTTP token (no Symfony kernel).
 */
final class IntegrationApiKeysSaveFlowTest extends TestCase
{
    public function testNewTokenIsEncryptedAndResolvableAfterSavePipeline(): void
    {
        $integration = new Integration(true, [], InsightsApiConfig::NAME, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => '',
        ]);
        $oldKeys = $integration->getApiKeys();

        // Simulate admin POST with a new token.
        $integration->setApiKeys([
            InsightsApiConfig::HTTP_TOKEN_FIELD => 'brand-new-token',
        ]);

        $subscriber = new ApiKeysBeforeSaveSubscriber();
        $subscriber->onApiKeysBeforeSave(new KeysSaveEvent($integration, $oldKeys));

        $encryption = new SafeEncryptionService($this->createEncryptingHelper());
        $encrypted = $encryption->encrypt($integration->getApiKeys());

        $this->assertIsString($encrypted[InsightsApiConfig::HTTP_TOKEN_FIELD]);
        $this->assertStringContainsString('|', $encrypted[InsightsApiConfig::HTTP_TOKEN_FIELD]);

        $integration->setApiKeys($encrypted);
        $decrypted = $encryption->decrypt($integration->getApiKeys());
        $integration->setApiKeys(\is_array($decrypted) ? $decrypted : []);

        $resolver = new InsightsHttpTokenResolver($this->createEncryptingHelper());
        $this->assertSame('brand-new-token', $resolver->resolve($integration));
    }

    private function createEncryptingHelper(): EncryptionHelper
    {
        return new class extends EncryptionHelper {
            public function __construct()
            {
            }

            public function encrypt(mixed $data): string
            {
                $payload = base64_encode((string) $data);

                return $payload.'|'.base64_encode('iv');
            }

            public function decrypt(mixed $key, bool $mainDecryptOnly = false)
            {
                if (!\is_string($key) || !str_contains($key, '|')) {
                    return false;
                }
                [$payload] = explode('|', $key, 2);

                return base64_decode($payload, true);
            }
        };
    }
}
