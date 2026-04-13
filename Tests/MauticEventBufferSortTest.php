<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests;

use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticInsightsBundle\Buffer\DisabledPersistentEventBuffer;
use MauticPlugin\MauticInsightsBundle\Buffer\MauticEventBuffer;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Service\InsightsHttpTokenResolver;
use MauticPlugin\MauticInsightsBundle\Service\InsightsPublishedIntegrationFinder;
use MauticPlugin\MauticInsightsBundle\Service\NoopInsightsApiSendGate;
use MauticPlugin\MauticInsightsBundle\Service\ResilientEventSender;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * {@see MauticEventBuffer} sorts outbound batches by {@code ts} (oldest first).
 */
final class MauticEventBufferSortTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $capturedRequests = [];

    public function testFlushSendsEventsSortedByTsOldestFirst(): void
    {
        $client = $this->createCapturingClient();
        $buffer = $this->createBuffer($client);

        // Push newer first; data['ts'] overrides auto ts (see MauticEventBuffer::push).
        $buffer->push('late', ['ts' => '2026-03-03T12:00:00+00:00', 'n' => 3]);
        $buffer->push('early', ['ts' => '2026-01-01T08:00:00+00:00', 'n' => 1]);
        $buffer->push('mid', ['ts' => '2026-02-02T10:00:00+00:00', 'n' => 2]);
        $buffer->flush();

        $this->assertCount(1, $this->capturedRequests);
        $decoded = json_decode($this->capturedRequests[0]['options']['body'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        $rows = $decoded['events'] ?? [];
        $this->assertCount(3, $rows);
        $this->assertSame(1, $rows[0]['n'] ?? null);
        $this->assertSame(2, $rows[1]['n'] ?? null);
        $this->assertSame(3, $rows[2]['n'] ?? null);
    }

    public function testPushOrderPreservedWhenWallClockSecondIsIdentical(): void
    {
        $client = $this->createCapturingClient();
        $buffer = $this->createBuffer($client);

        $sameSecond = '2026-06-01T12:00:00+00:00';
        $buffer->push('third', ['ts' => $sameSecond, 'n' => 3]);
        $buffer->push('first', ['ts' => $sameSecond, 'n' => 1]);
        $buffer->push('second', ['ts' => $sameSecond, 'n' => 2]);
        $buffer->flush();

        $decoded = json_decode($this->capturedRequests[0]['options']['body'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        $rows = $decoded['events'] ?? [];
        $this->assertCount(3, $rows);
        $this->assertSame(3, $rows[0]['n'] ?? null);
        $this->assertSame(1, $rows[1]['n'] ?? null);
        $this->assertSame(2, $rows[2]['n'] ?? null);
    }

    public function testAutoTsUsesFractionalSecondsSoRapidPushesStayOrdered(): void
    {
        $client = $this->createCapturingClient();
        $buffer = $this->createBuffer($client);

        $buffer->push('a', ['n' => 1]);
        $buffer->push('b', ['n' => 2]);
        $buffer->push('c', ['n' => 3]);
        $buffer->flush();

        $decoded = json_decode($this->capturedRequests[0]['options']['body'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        $rows = $decoded['events'] ?? [];
        $this->assertCount(3, $rows);
        $this->assertSame(1, $rows[0]['n'] ?? null);
        $this->assertSame(2, $rows[1]['n'] ?? null);
        $this->assertSame(3, $rows[2]['n'] ?? null);
    }

    public function testEventsWithoutParseableTsAreSentLastPreservingPushOrder(): void
    {
        $client = $this->createCapturingClient();
        $buffer = $this->createBuffer($client);

        $buffer->push('bad-ts', ['ts' => 'not-a-date', 'n' => 'bad']);
        $buffer->push('ok', ['ts' => '2026-01-15T00:00:00+00:00', 'n' => 'ok']);
        // Empty ts overrides auto timestamp (see array_merge order in push); treated as missing for sort.
        $buffer->push('empty-ts', ['ts' => '', 'n' => 'empty']);
        $buffer->flush();

        $decoded = json_decode($this->capturedRequests[0]['options']['body'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        $rows = $decoded['events'] ?? [];
        $this->assertSame('ok', $rows[0]['n'] ?? null);
        $this->assertSame('bad', $rows[1]['n'] ?? null);
        $this->assertSame('empty', $rows[2]['n'] ?? null);
    }

    private function createCapturingClient(): MockHttpClient
    {
        $this->capturedRequests = [];

        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->capturedRequests[] = [
                'method' => $method,
                'url' => $url,
                'options' => $options,
            ];

            return new MockResponse('{}', ['http_code' => 200]);
        });
    }

    private function createBuffer(MockHttpClient $client): MauticEventBuffer
    {
        $integration = new Integration(true, [], InsightsApiConfig::NAME, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => 'tok',
        ]);
        $finder = InsightsPublishedIntegrationFinder::createForTest($integration);
        $sender = new ResilientEventSender($client, new NullLogger(), 1, 0);

        return new MauticEventBuffer(
            $sender,
            $finder,
            new InsightsHttpTokenResolver(null),
            new DisabledPersistentEventBuffer(),
            new NoopInsightsApiSendGate(),
            5000
        );
    }
}
