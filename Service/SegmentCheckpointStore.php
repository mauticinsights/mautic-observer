<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use Psr\Log\LoggerInterface;

/**
 * Reads and writes the segment membership checkpoint — a JSON file mapping
 * {@code contact_id → list<int>} (segment ids the contact belongs to).
 *
 * The checkpoint is used by {@see SegmentReconcileService} to compute the
 * delta between the last known membership and the current state of
 * {@code lead_lists_leads} after a batch rebuild.
 *
 * File format:
 * <code>
 * {
 *   "version": 1,
 *   "updated_at": "2026-01-15T12:00:00Z",
 *   "memberships": { "12": [1, 5], "34": [3, 8, 10] }
 * }
 * </code>
 */
final class SegmentCheckpointStore
{
    private const FILENAME = 'segment_checkpoint.json';

    public function __construct(
        private readonly string $dataDir,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<int, list<int>> contact_id → list of segment ids
     */
    public function load(): array
    {
        $path = $this->path();
        if (!file_exists($path)) {
            $this->logger->debug('MauticInsights: segment checkpoint not found — starting fresh.', ['path' => $path]);

            return [];
        }

        $raw = file_get_contents($path);
        if (!\is_string($raw) || '' === $raw) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning('MauticInsights: corrupt segment checkpoint — resetting.', ['error' => $e->getMessage()]);

            return [];
        }

        if (!\is_array($decoded) || !isset($decoded['memberships']) || !\is_array($decoded['memberships'])) {
            return [];
        }

        $memberships = [];
        foreach ($decoded['memberships'] as $contactId => $listIds) {
            $id = (int) $contactId;
            if ($id <= 0) {
                continue;
            }
            $memberships[$id] = array_unique(array_map('intval', \is_array($listIds) ? $listIds : []));
        }

        return $memberships;
    }

    /**
     * @param array<int, list<int>> $memberships contact_id → list of segment ids
     */
    public function save(array $memberships): void
    {
        $dir = \dirname($this->path());
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                $this->logger->error('MauticInsights: cannot create checkpoint directory.', ['dir' => $dir]);

                return;
            }
        }

        $payload = [
            'version' => 1,
            'updated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'memberships' => $memberships,
        ];

        $tmpPath = $this->path().'.tmp';
        $written = @file_put_contents($tmpPath, json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
        if (false === $written) {
            $this->logger->error('MauticInsights: failed to write segment checkpoint.', ['path' => $tmpPath]);

            return;
        }

        if (!@rename($tmpPath, $this->path())) {
            $this->logger->error('MauticInsights: failed to atomically replace segment checkpoint.', ['path' => $this->path()]);
            @unlink($tmpPath);
        }
    }

    private function path(): string
    {
        return rtrim($this->dataDir, '/').'/'.self::FILENAME;
    }
}
