<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Persists the outcome of the last outbound Insights API calls per channel (event ingest vs status), for the Health tab.
 */
final class InsightsLastApiTelemetry
{
    public function __construct(
        private string $dataDir,
        private LoggerInterface $logger,
    ) {
        if (!is_dir($this->dataDir)) {
            @mkdir($this->dataDir, 0755, true);
        }
    }

    private function path(): string
    {
        return $this->dataDir.'/mautic_insights_last_api.json';
    }

    public function record(string $url, string $method, int $httpStatus, bool $ok, ?string $error = null): void
    {
        $channel = $this->channelForUrl($url);
        $payload = [
            'ok' => $ok,
            'at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(\DATE_ATOM),
            'url' => $url,
            'method' => $method,
            'http_status' => $httpStatus,
            'error' => $error,
        ];

        try {
            $buckets = $this->loadBuckets();
            $buckets[$channel] = $payload;
            file_put_contents(
                $this->path(),
                json_encode($buckets, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
                LOCK_EX
            );
        } catch (JsonException $e) {
            $this->logger->warning('Mautic Insights: could not encode last API telemetry: '.$e->getMessage(), ['exception' => $e]);
        } catch (Throwable $e) {
            $this->logger->warning('Mautic Insights: could not write last API telemetry: '.$e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * @return array{ok: bool, at: string, url: string, method: string, http_status: int, error: ?string}|null
     */
    public function getLastEvent(): ?array
    {
        $b = $this->loadBuckets();

        return $b['event'];
    }

    /**
     * @return array{ok: bool, at: string, url: string, method: string, http_status: int, error: ?string}|null
     */
    public function getLastStatus(): ?array
    {
        $b = $this->loadBuckets();

        return $b['status'];
    }

    /**
     * @return array{event: ?array{ok: bool, at: string, url: string, method: string, http_status: int, error: ?string}, status: ?array{ok: bool, at: string, url: string, method: string, http_status: int, error: ?string}}
     */
    private function loadBuckets(): array
    {
        $path = $this->path();
        if (!is_readable($path)) {
            return ['event' => null, 'status' => null];
        }

        try {
            $raw = file_get_contents($path);
            if (false === $raw || '' === trim($raw)) {
                return ['event' => null, 'status' => null];
            }
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!\is_array($decoded)) {
                return ['event' => null, 'status' => null];
            }

            return $this->normalizeFileToBuckets($decoded);
        } catch (Throwable $e) {
            $this->logger->debug('Mautic Insights: could not read last API telemetry: '.$e->getMessage(), ['exception' => $e]);

            return ['event' => null, 'status' => null];
        }
    }

    /**
     * @param array<string, mixed> $decoded
     *
     * @return array{event: ?array{ok: bool, at: string, url: string, method: string, http_status: int, error: ?string}, status: ?array{ok: bool, at: string, url: string, method: string, http_status: int, error: ?string}}
     */
    private function normalizeFileToBuckets(array $decoded): array
    {
        if (isset($decoded['event']) || isset($decoded['status'])) {
            return [
                'event' => $this->normalizeRow($decoded['event'] ?? null),
                'status' => $this->normalizeRow($decoded['status'] ?? null),
            ];
        }

        // Legacy single-object file (one global "last" row).
        if (isset($decoded['ok'], $decoded['at'])) {
            $row = $this->normalizeRow($decoded);
            if (null === $row) {
                return ['event' => null, 'status' => null];
            }
            $ch = $this->channelForUrl($row['url']);

            return 'status' === $ch
                ? ['event' => null, 'status' => $row]
                : ['event' => $row, 'status' => null];
        }

        return ['event' => null, 'status' => null];
    }

    /**
     * @return array{ok: bool, at: string, url: string, method: string, http_status: int, error: ?string}|null
     */
    private function normalizeRow(mixed $row): ?array
    {
        if (!\is_array($row)) {
            return null;
        }

        return [
            'ok' => (bool) ($row['ok'] ?? false),
            'at' => (string) ($row['at'] ?? ''),
            'url' => (string) ($row['url'] ?? ''),
            'method' => (string) ($row['method'] ?? 'POST'),
            'http_status' => (int) ($row['http_status'] ?? 0),
            'error' => isset($row['error']) ? (string) $row['error'] : null,
        ];
    }

    private function channelForUrl(string $url): string
    {
        $trim = rtrim($url, '/');
        if (InsightsApiConfig::STATUS_NOTIFY_URL === $url || str_ends_with($trim, '/status')) {
            return 'status';
        }

        return 'event';
    }
}
