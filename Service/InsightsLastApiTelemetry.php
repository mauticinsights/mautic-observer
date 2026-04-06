<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Persists the outcome of the last outbound Insights API HTTP call (for the Health tab).
 */
final class InsightsLastApiTelemetry
{
    public function __construct(
        private string $cacheDir,
        private LoggerInterface $logger,
    ) {
    }

    private function path(): string
    {
        return $this->cacheDir.'/mautic_insights_last_api.json';
    }

    public function record(string $url, string $method, int $httpStatus, bool $ok, ?string $error = null): void
    {
        $data = [
            'ok' => $ok,
            'at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(\DATE_ATOM),
            'url' => $url,
            'method' => $method,
            'http_status' => $httpStatus,
            'error' => $error,
        ];

        try {
            file_put_contents($this->path(), json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX);
        } catch (JsonException $e) {
            $this->logger->warning('Mautic Insights: could not encode last API telemetry: '.$e->getMessage(), ['exception' => $e]);
        } catch (Throwable $e) {
            $this->logger->warning('Mautic Insights: could not write last API telemetry: '.$e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * @return array{ok: bool, at: string, url: string, method: string, http_status: int, error: ?string}|null
     */
    public function getLast(): ?array
    {
        $path = $this->path();
        if (!is_readable($path)) {
            return null;
        }

        try {
            $raw = file_get_contents($path);
            if (false === $raw || '' === $raw) {
                return null;
            }
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!\is_array($decoded)) {
                return null;
            }

            return [
                'ok' => (bool) ($decoded['ok'] ?? false),
                'at' => (string) ($decoded['at'] ?? ''),
                'url' => (string) ($decoded['url'] ?? ''),
                'method' => (string) ($decoded['method'] ?? 'POST'),
                'http_status' => (int) ($decoded['http_status'] ?? 0),
                'error' => isset($decoded['error']) ? (string) $decoded['error'] : null,
            ];
        } catch (Throwable $e) {
            $this->logger->debug('Mautic Insights: could not read last API telemetry: '.$e->getMessage(), ['exception' => $e]);

            return null;
        }
    }
}
