<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * POST JSON to the Insights API with retries on transport errors, HTTP 429 and 5xx.
 */
final class ResilientEventSender
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private int $maxAttempts = 3,
        private int $initialBackoffMs = 250,
        private ?InsightsLastApiTelemetry $lastApiTelemetry = null,
    ) {
    }

    /**
     * POST `{"events":[...]}` — one request for multiple event records.
     *
     * @param list<array<string, mixed>> $events
     */
    public function sendEvents(string $url, string $bearerToken, array $events): bool
    {
        if ([] === $events) {
            return true;
        }

        return $this->send($url, $bearerToken, ['events' => $events]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function send(string $url, string $bearerToken, array $payload, string $method = 'POST'): bool
    {
        if ('POST' === $method && InsightsApiConfig::EVENT_NOTIFY_URL === $url) {
            if (!isset($payload['events']) || !\is_array($payload['events']) || [] === $payload['events']) {
                $this->logger->critical('Mautic Insights: POST event endpoint body must include a non-empty JSON array "events" — request aborted.');
                $this->recordTelemetry($url, $method, 0, false, 'empty or invalid events payload');

                return false;
            }
        }

        $attempt = 0;
        $lastStatus = 0;
        $lastTransportError = null;

        while ($attempt < $this->maxAttempts) {
            ++$attempt;

            try {
                $response = $this->httpClient->request($method, $url, [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer '.$bearerToken,
                    ],
                    'json' => $payload,
                    'timeout' => 15,
                ]);

                $status = $response->getStatusCode();
                $lastStatus = $status;

                $kind = match (true) {
                    $status >= 200 && $status < 300 => 'success',
                    429 === $status || $status >= 500 => 'retry',
                    default => 'no_retry',
                };

                if ('success' === $kind) {
                    $this->recordTelemetry($url, $method, $status, true);

                    return true;
                }

                if ('retry' === $kind) {
                    $this->logger->notice(
                        sprintf('Mautic Insights: %s %s returned %d, attempt %d/%d', $method, $url, $status, $attempt, $this->maxAttempts),
                        ['payload' => $payload]
                    );
                    if ($attempt < $this->maxAttempts) {
                        $this->sleepBackoff($attempt);
                    }

                    continue;
                }

                $this->logger->critical(
                    sprintf('Mautic Insights: %s %s returned HTTP %d (no retry) — events lost.', $method, $url, $status),
                    ['body' => $response->getContent(false), 'payload' => $payload]
                );
                $this->recordTelemetry($url, $method, $status, false, 'HTTP '.$status);

                return false;
            } catch (TransportExceptionInterface $e) {
                $lastTransportError = $e->getMessage();
                $this->logger->notice(
                    sprintf('Mautic Insights: event POST transport error, attempt %d/%d: %s', $attempt, $this->maxAttempts, $e->getMessage()),
                    ['exception' => $e]
                );
                if ($attempt < $this->maxAttempts) {
                    $this->sleepBackoff($attempt);
                }
            } catch (Throwable $e) {
                $this->logger->critical(
                    sprintf('Mautic Insights: event POST unexpected error: %s — events lost.', $e->getMessage()),
                    ['exception' => $e, 'payload' => $payload]
                );
                $this->recordTelemetry($url, $method, $lastStatus, false, $e->getMessage());

                return false;
            }
        }

        $this->logger->critical(
            sprintf('Mautic Insights: %s %s failed after %d attempts — events lost.', $method, $url, $this->maxAttempts),
            ['payload' => $payload]
        );
        $this->recordTelemetry($url, $method, $lastStatus, false, $lastTransportError ?? 'max retries exceeded');

        return false;
    }

    private function recordTelemetry(string $url, string $method, int $httpStatus, bool $ok, ?string $error = null): void
    {
        $this->lastApiTelemetry?->record($url, $method, $httpStatus, $ok, $error);
    }

    private function sleepBackoff(int $attempt): void
    {
        $ms = $this->initialBackoffMs * (2 ** ($attempt - 1));
        usleep($ms * 1000);
    }
}
