<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests;

use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Service\InsightsHttpTokenResolver;
use MauticPlugin\MauticInsightsBundle\Service\InsightsPublishedIntegrationFinder;
use MauticPlugin\MauticInsightsBundle\Service\MauticEventBuffer;
use MauticPlugin\MauticInsightsBundle\Service\NoopInsightsApiSendGate;
use MauticPlugin\MauticInsightsBundle\Service\ResilientEventSender;
use MauticPlugin\MauticInsightsBundle\Tests\Stubs\EnabledNopPersistentBuffer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class EventEndpointFunctionalTest extends TestCase
{
    /**
     * @var list<array{method: string, url: string, options: array<string, mixed>}>
     */
    private array $capturedRequests = [];

    public function testFlushPostsJsonWithEventsKeyAndBearer(): void
    {
        $client = $this->createCapturingClient();
        $buffer = $this->createBuffer($client, 1000);

        $buffer->push('mautic.email.on_send', []);
        $buffer->push('mautic.lead_post_save', []);
        $buffer->flush();

        $this->assertCount(1, $this->capturedRequests);
        $req = $this->capturedRequests[0];
        $this->assertSame('POST', $req['method']);
        $this->assertSame(InsightsApiConfig::EVENT_NOTIFY_URL, $req['url']);
        $authHeader = $req['options']['normalized_headers']['authorization'][0] ?? '';
        $this->assertStringContainsString('Bearer test-token', $authHeader);

        $decoded = json_decode($req['options']['body'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('events', $decoded);
        $this->assertCount(2, $decoded['events']);
        $this->assertSame('mautic.email.on_send', $decoded['events'][0]['event']);
        $this->assertSame('mautic.lead_post_save', $decoded['events'][1]['event']);
        $this->assertArrayHasKey('ts', $decoded['events'][0]);
    }

    public function testLargeFlushIsChunkedIntoMultiplePosts(): void
    {
        $client = $this->createCapturingClient();
        $buffer = $this->createBuffer($client, 10_000);

        for ($i = 0; $i < 101; ++$i) {
            $buffer->push('mautic.email.on_send', []);
        }
        $buffer->flush();

        $this->assertCount(2, $this->capturedRequests);
        $first = json_decode($this->capturedRequests[0]['options']['body'], true, 512, JSON_THROW_ON_ERROR);
        $second = json_decode($this->capturedRequests[1]['options']['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(100, $first['events']);
        $this->assertCount(1, $second['events']);
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

    private function createBuffer(MockHttpClient $client, int $flushSize): MauticEventBuffer
    {
        $integration = new Integration(true, [], InsightsApiConfig::NAME, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => 'test-token',
        ]);
        $finder = InsightsPublishedIntegrationFinder::createForTest($integration);
        $sender = new ResilientEventSender($client, new NullLogger(), 1, 0);

        return new MauticEventBuffer($sender, $finder, new InsightsHttpTokenResolver(null), new EnabledNopPersistentBuffer(), new NoopInsightsApiSendGate(), $flushSize);
    }
}
