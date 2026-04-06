<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

/** For unit tests that construct {@see MauticEventBuffer} without outage logic. */
final class NoopInsightsApiSendGate implements InsightsApiSendGate
{
    public function tick(): void
    {
    }

    public function shouldSkipHttpThisFlush(): bool
    {
        return false;
    }

    public function afterFlush(bool $httpAttempted, bool $everyChunkSucceeded): void
    {
    }

    public function resetStateOnIntegrationConfigChange(): void
    {
    }
}
