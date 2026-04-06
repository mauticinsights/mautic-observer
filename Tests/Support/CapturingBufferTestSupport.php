<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\Support;

use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Service\DisabledPersistentEventBuffer;
use MauticPlugin\MauticInsightsBundle\Service\InsightsHttpTokenResolver;
use MauticPlugin\MauticInsightsBundle\Service\InsightsPublishedIntegrationFinder;
use MauticPlugin\MauticInsightsBundle\Service\MauticEventBuffer;
use MauticPlugin\MauticInsightsBundle\Service\NoopInsightsApiSendGate;
use MauticPlugin\MauticInsightsBundle\Service\ResilientEventSender;
use MauticPlugin\MauticInsightsBundle\Tests\Stubs\EnabledNopPersistentBuffer;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Shared helpers for tests that flush {@see MauticEventBuffer} and inspect captured POST JSON ({@see InsightsApiConfig::EVENT_NOTIFY_URL}).
 */
trait CapturingBufferTestSupport
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $capturedEventApiRequests = [];

    private function createCapturingEventHttpClient(): MockHttpClient
    {
        $this->capturedEventApiRequests = [];

        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->capturedEventApiRequests[] = [
                'method' => $method,
                'url' => $url,
                'options' => $options,
            ];

            return new MockResponse('{}', ['http_code' => 200]);
        });
    }

    /**
     * @param bool $usePersistentBuffer {@see EnabledNopPersistentBuffer} (integration durable buffer ON) vs {@see DisabledPersistentEventBuffer} (OFF).
     *                                  Both paths must deliver the same deferred rows to {@see InsightsApiConfig::EVENT_NOTIFY_URL}.
     */
    private function createMauticEventBufferForTests(MockHttpClient $client, int $flushSize = 5000, bool $usePersistentBuffer = true): MauticEventBuffer
    {
        $integration = new Integration(true, [], InsightsApiConfig::NAME, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => 'test-token',
        ]);
        $finder = InsightsPublishedIntegrationFinder::createForTest($integration);
        $sender = new ResilientEventSender($client, new NullLogger(), 1, 0);
        $tokenResolver = new InsightsHttpTokenResolver(null);

        $persistent = $usePersistentBuffer
            ? new EnabledNopPersistentBuffer()
            : new DisabledPersistentEventBuffer();

        return new MauticEventBuffer(
            $sender,
            $finder,
            $tokenResolver,
            $persistent,
            new NoopInsightsApiSendGate(),
            $flushSize,
            InsightsApiConfig::EVENT_NOTIFY_URL,
            'events',
        );
    }

    private function newMainRequestTerminateEvent(): TerminateEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new TerminateEvent($kernel, Request::create('/'), new Response());
    }

    /**
     * Flushes the buffer and returns `events[]` rows without the `ts` field (the buffer generates ISO timestamps).
     *
     * @return list<array<string, mixed>>
     */
    private function flushBufferAndGetNormalizedEventRows(MauticEventBuffer $buffer): array
    {
        $buffer->flush();
        if ([] === $this->capturedEventApiRequests) {
            return [];
        }

        $this->assertCount(1, $this->capturedEventApiRequests);
        $this->assertSame(InsightsApiConfig::EVENT_NOTIFY_URL, $this->capturedEventApiRequests[0]['url']);

        $decoded = json_decode($this->capturedEventApiRequests[0]['options']['body'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('events', $decoded);
        $out = [];
        foreach ($decoded['events'] as $row) {
            $this->assertArrayHasKey('ts', $row);
            unset($row['ts']);
            $out[] = $row;
        }

        return $out;
    }
}
