<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

/**
 * Gates outbound Insights API HTTP calls when the API is down (exponential backoff) and coordinates
 * prolonged-outage handling ({@see InsightsApiOutageCoordinator}).
 */
interface InsightsApiSendGate
{
    /**
     * Run on every main-request flush (even when the event buffer is empty): may unpublish the integration
     * after {@see InsightsApiOutageCoordinator::OUTAGE_DISABLE_AFTER_SECONDS} of continuous failed delivery.
     */
    public function tick(): void;

    /**
     * When true, {@see MauticEventBuffer} skips HTTP for this flush and re-queues in-memory rows only.
     */
    public function shouldSkipHttpThisFlush(): bool;

    /**
     * @param bool $httpAttempted       whether any HTTP request was made this flush
     * @param bool $everyChunkSucceeded when {@code $httpAttempted}, whether all batches succeeded
     */
    public function afterFlush(bool $httpAttempted, bool $everyChunkSucceeded): void;

    /**
     * Clears backoff / outage tracking when the user saves integration settings (e.g. after re-enabling).
     */
    public function resetStateOnIntegrationConfigChange(): void;
}
