<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Manages the persistent sync session ID stored in a local JSON file.
 *
 * The sync_id is generated once when a sync session starts and lives for the
 * full lifetime of the plugin session (survives cache clears; resets only on
 * a new sync trigger).  It is injected into every outbound event batch at the
 * payload root (key {@code sync_id}) so the app can correlate all events —
 * both bulk sync and live — to their originating session.
 *
 * Persistence is intentionally file-based rather than DB-based to avoid
 * Doctrine ORM identity-map conflicts: Mautic's IntegrationsHelper::saveIntegrationConfiguration()
 * temporarily restores plain-text apiKeys onto the Integration entity after
 * the DB flush.  Any subsequent EntityManager::flush() in the same PHP process
 * (e.g. during kernel.terminate bulk sync) would overwrite the encrypted apiKeys
 * in the database with plain text, causing the HTTP token to appear lost on the
 * next page load.
 */
final class SyncSessionManager
{
    private ?string $cachedSyncId = null;
    private bool $resolved = false;

    public function __construct(
        private string $dataDir,
        private ?LoggerInterface $logger = null,
    ) {
        if ('' !== $this->dataDir && !is_dir($this->dataDir)) {
            @mkdir($this->dataDir, 0755, true);
        }
    }

    /**
     * Returns the current sync_id, or null when no session file exists yet.
     */
    public function getSyncId(): ?string
    {
        if ($this->resolved) {
            return $this->cachedSyncId;
        }

        $this->resolved = true;
        $id = $this->readFromFile();
        if (null !== $id) {
            $this->cachedSyncId = $id;
        }

        return $this->cachedSyncId;
    }

    /**
     * Generates a new UUID4 sync session ID, persists it to the state file,
     * and updates the in-memory cache.
     *
     * Should be called at the start of every BulkSyncService::syncAll() run.
     */
    public function createNewSession(): string
    {
        $syncId = $this->generateUuidV4();

        try {
            $this->writeToFile($syncId);
        } catch (Throwable $e) {
            $this->logger?->error(
                'MauticInsights SyncSessionManager: failed to persist sync_id to file — continuing with in-memory value.',
                ['exception' => $e->getMessage()],
            );
        }

        $this->cachedSyncId = $syncId;
        $this->resolved = true;

        return $syncId;
    }

    private function statePath(): string
    {
        return rtrim($this->dataDir, '/').'/sync_session.json';
    }

    private function readFromFile(): ?string
    {
        $path = $this->statePath();
        if (!is_readable($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (false === $raw || '' === trim($raw)) {
            return null;
        }

        try {
            $data = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        $id = $data[InsightsApiConfig::FEATURE_SYNC_ID] ?? null;

        return \is_string($id) && '' !== $id ? $id : null;
    }

    private function writeToFile(string $syncId): void
    {
        $data = [InsightsApiConfig::FEATURE_SYNC_ID => $syncId];
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $path = $this->statePath();
        $tmp = $path.'.tmp.'.getmypid();

        if (false === file_put_contents($tmp, $json, LOCK_EX)) {
            throw new RuntimeException('Could not write sync session file: '.$tmp);
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Could not rename sync session file to: '.$path);
        }
    }

    private function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = \chr(\ord($data[6]) & 0x0F | 0x40);
        $data[8] = \chr(\ord($data[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
