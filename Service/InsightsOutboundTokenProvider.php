<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use Psr\Log\LoggerInterface;

/**
 * Resolves the outbound Bearer token once per request (Mautic's {@code Integration} is request-scoped in practice;
 * the underlying {@link InsightsPublishedIntegrationFinder} does not use ORM result cache).
 *
 * When the integration is unpublished or the API key is empty, {@see getToken()} returns null and
 * the plugin must not enqueue payloads, must not write the durable buffer, and should clear the durable
 * store so a disabled instance does not retain stale rows.
 */
final class InsightsOutboundTokenProvider
{
    private bool $resolved = false;

    private ?string $cachedToken = null;

    public function __construct(
        private InsightsPublishedIntegrationFinder $integrationFinder,
        private InsightsHttpTokenResolver $httpTokenResolver,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function getToken(): ?string
    {
        if ($this->resolved) {
            return $this->cachedToken;
        }

        $this->resolved = true;
        $integration = $this->integrationFinder->findPublished();
        if (null === $integration) {
            $this->logger?->debug('MauticInsights: integration not found or not published — no token.', [
                'url' => InsightsApiConfig::EVENT_NOTIFY_URL,
            ]);
            $this->cachedToken = null;

            return null;
        }

        $token = $this->httpTokenResolver->resolve($integration);
        if ('' === $token) {
            $this->logger?->warning('MauticInsights: integration is published but API key (http_token) is empty.', [
                'url' => InsightsApiConfig::EVENT_NOTIFY_URL,
            ]);
            $this->cachedToken = null;

            return null;
        }

        $this->cachedToken = $token;

        return $this->cachedToken;
    }
}
