<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Manages the persistent sync session ID stored in {@see InsightsApiConfig::FEATURE_SYNC_ID}
 * inside the Integration entity's {@code feature_settings} array.
 *
 * The sync_id is generated once when a sync session starts and lives for the full lifetime of
 * the plugin session (survives cache clears; resets only on a new sync trigger).
 *
 * It is injected into every outbound event batch at the payload root (key {@code sync_id}) so
 * the app can correlate all events — both bulk sync and live — to their originating session.
 */
final class SyncSessionManager
{
    private ?string $cachedSyncId = null;
    private bool $resolved = false;

    public function __construct(
        private InsightsPublishedIntegrationFinder $integrationFinder,
        private EntityManagerInterface $entityManager,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Returns the current sync_id or null when the integration is not published / has no session yet.
     */
    public function getSyncId(): ?string
    {
        if ($this->resolved) {
            return $this->cachedSyncId;
        }

        $this->resolved = true;
        $integration = $this->integrationFinder->findPublished();
        if (null === $integration) {
            return null;
        }

        $settings = $integration->getFeatureSettings();
        $id = $settings[InsightsApiConfig::FEATURE_SYNC_ID] ?? null;
        if (\is_string($id) && '' !== $id) {
            $this->cachedSyncId = $id;
        }

        return $this->cachedSyncId;
    }

    /**
     * Generates a new UUID4 sync session ID, persists it to the Integration entity's
     * feature_settings, and updates the in-memory cache.
     *
     * Should be called at the start of every {@see BulkSyncService::syncAll()} run.
     */
    public function createNewSession(): string
    {
        $syncId = $this->generateUuidV4();

        $integration = $this->integrationFinder->findPublished();
        if (null !== $integration) {
            try {
                $settings = $integration->getFeatureSettings();
                $settings[InsightsApiConfig::FEATURE_SYNC_ID] = $syncId;
                $integration->setFeatureSettings($settings);
                $this->entityManager->persist($integration);
                $this->entityManager->flush();
            } catch (Throwable $e) {
                $this->logger?->error(
                    'MauticInsights SyncSessionManager: failed to persist sync_id — continuing with in-memory value.',
                    ['exception' => $e->getMessage()],
                );
            }
        }

        // Update in-memory cache so getSyncId() returns the new value immediately.
        $this->cachedSyncId = $syncId;
        $this->resolved = true;

        return $syncId;
    }

    private function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = \chr(\ord($data[6]) & 0x0F | 0x40);
        $data[8] = \chr(\ord($data[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
