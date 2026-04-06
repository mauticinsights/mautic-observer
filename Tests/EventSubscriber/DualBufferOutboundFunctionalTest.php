<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\EventSubscriber;

use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\MauticInsightsBundle\EventSubscriber\EventBufferFlushSubscriber;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Service\DeferredOutboundEventQueue;
use MauticPlugin\MauticInsightsBundle\Tests\Support\CapturingBufferTestSupport;
use PHPUnit\Framework\TestCase;

/**
 * Documents outbound JSON shape (live tests against a mock HTTP client).
 *
 * **POST `/api/event`** — root key `events` (all buffered rows, including segment lifecycle):
 *
 * ```json
 * {
 *   "events": [
 *     {
 *       "event": "mautic.email.on_send",
 *       "ts": "2026-04-03T12:00:00+00:00",
 *       "channel": "email",
 *       "email_id": 7,
 *       "properties": { "email_name": "…" }
 *     },
 *     {
 *       "event": "mautic.lead_list_post_save",
 *       "ts": "2026-04-03T12:00:00+00:00",
 *       "channel": "segment",
 *       "segment_id": 10,
 *       "properties": { "name": "VIP", "alias": "vip", "operation": "created" }
 *     }
 *   ]
 * }
 * ```
 */
final class DualBufferOutboundFunctionalTest extends TestCase
{
    use CapturingBufferTestSupport;

    public function testEventBufferPostBodyUsesEventsArrayKey(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);

        $buffer->push('mautic.insights.example', [
            'channel' => 'test',
            'properties' => ['note' => 'visible in JSON below'],
        ]);
        $buffer->flush();

        $this->assertCount(1, $this->capturedEventApiRequests);
        $this->assertSame('POST', $this->capturedEventApiRequests[0]['method']);
        $this->assertSame(InsightsApiConfig::EVENT_NOTIFY_URL, $this->capturedEventApiRequests[0]['url']);

        $rawBody = (string) ($this->capturedEventApiRequests[0]['options']['body'] ?? '');
        $this->assertJson($rawBody);

        $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('events', $decoded);
        $this->assertCount(1, $decoded['events']);

        $row = $decoded['events'][0];
        $this->assertSame('mautic.insights.example', $row['event']);
        $this->assertArrayNotHasKey('count', $row);
        $this->assertSame('test', $row['channel']);
        $this->assertSame(['note' => 'visible in JSON below'], $row['properties']);
        $this->assertArrayHasKey('ts', $row);

        $expectedPretty = <<<'JSON'
{
    "events": [
        {
            "event": "mautic.insights.example",
            "channel": "test",
            "properties": {
                "note": "visible in JSON below"
            }
        }
    ]
}
JSON;
        $normalized = $decoded;
        unset($normalized['events'][0]['ts']);
        $actualPretty = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertJsonStringEqualsJsonString($expectedPretty, (string) $actualPretty);
    }

    public function testSegmentLifecycleRowsUseEventsArrayKeyOnEventUrl(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);

        $buffer->push(LeadEvents::LIST_POST_SAVE, [
            'channel' => 'segment',
            'segment_id' => 42,
            'properties' => [
                'name' => 'Newsletter list',
                'alias' => 'newsletter',
                'operation' => 'updated',
            ],
        ]);
        $buffer->flush();

        $this->assertCount(1, $this->capturedEventApiRequests);
        $this->assertSame(InsightsApiConfig::EVENT_NOTIFY_URL, $this->capturedEventApiRequests[0]['url']);

        $rawBody = (string) ($this->capturedEventApiRequests[0]['options']['body'] ?? '');
        $this->assertJson($rawBody);

        $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('events', $decoded);
        $this->assertCount(1, $decoded['events']);

        $row = $decoded['events'][0];
        $this->assertSame(LeadEvents::LIST_POST_SAVE, $row['event']);
        $this->assertSame(42, $row['segment_id']);
        $this->assertSame('segment', $row['channel']);
        $this->assertSame([
            'name' => 'Newsletter list',
            'alias' => 'newsletter',
            'operation' => 'updated',
        ], $row['properties']);

        $expectedPretty = <<<'JSON'
{
    "events": [
        {
            "event": "mautic.lead_list_post_save",
            "channel": "segment",
            "segment_id": 42,
            "properties": {
                "name": "Newsletter list",
                "alias": "newsletter",
                "operation": "updated"
            }
        }
    ]
}
JSON;
        $normalized = $decoded;
        unset($normalized['events'][0]['ts']);
        $actualPretty = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertJsonStringEqualsJsonString($expectedPretty, (string) $actualPretty);
    }

    public function testFlushSubscriberBatchesMainBufferAndDeferredQueueInOnePost(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $main = $this->createMauticEventBufferForTests($client);
        $queue = new DeferredOutboundEventQueue();

        $main->push('mautic.flush.example', ['channel' => 'main']);
        $queue->enqueue(LeadEvents::LIST_POST_SAVE, [
            'channel' => 'segment',
            'segment_id' => 42,
            'properties' => ['name' => 'Newsletter', 'alias' => 'nl', 'operation' => 'created'],
        ]);
        $queue->enqueue(LeadEvents::LIST_POST_DELETE, [
            'channel' => 'segment',
            'segment_id' => 99,
            'properties' => ['name' => 'gone', 'operation' => 'deleted'],
        ]);

        $flush = new EventBufferFlushSubscriber($main, $queue);
        $flush->onKernelTerminate($this->newMainRequestTerminateEvent());

        $this->assertCount(1, $this->capturedEventApiRequests);
        $this->assertSame('POST', $this->capturedEventApiRequests[0]['method']);
        $this->assertSame(InsightsApiConfig::EVENT_NOTIFY_URL, $this->capturedEventApiRequests[0]['url']);

        $body = json_decode($this->capturedEventApiRequests[0]['options']['body'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('events', $body);
        $this->assertCount(3, $body['events']);

        $this->assertSame('mautic.flush.example', $body['events'][0]['event']);
        $this->assertSame(LeadEvents::LIST_POST_SAVE, $body['events'][1]['event']);
        $this->assertSame(42, $body['events'][1]['segment_id']);
        $this->assertSame(LeadEvents::LIST_POST_DELETE, $body['events'][2]['event']);
        $this->assertSame(99, $body['events'][2]['segment_id']);
    }
}
