<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\EventSubscriber;

use Generator;
use Mautic\EmailBundle\EmailEvents;
use Mautic\LeadBundle\LeadEvents;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Service\DeferredOutboundEventQueue;
use MauticPlugin\MauticInsightsBundle\Service\InsightsHttpTokenResolver;
use MauticPlugin\MauticInsightsBundle\Service\InsightsPublishedIntegrationFinder;
use MauticPlugin\MauticInsightsBundle\Service\MauticEventBuffer;
use MauticPlugin\MauticInsightsBundle\Service\ResilientEventSender;
use MauticPlugin\MauticInsightsBundle\Tests\Stubs\SeededBacklogPersistentBuffer;
use MauticPlugin\MauticInsightsBundle\Tests\Support\CapturingBufferTestSupport;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Ensures deferred → {@see MauticEventBuffer} delivery is identical whether the
 * integration durable buffer is enabled ({@see \MauticPlugin\MauticInsightsBundle\Tests\Stubs\EnabledNopPersistentBuffer})
 * or disabled ({@see \MauticPlugin\MauticInsightsBundle\Service\DisabledPersistentEventBuffer}).
 */
final class EventBufferFlushPersistentModesFunctionalTest extends TestCase
{
    use CapturingBufferTestSupport;

    /**
     * @return Generator<string, array{0: bool}>
     */
    public static function durableBufferModeProvider(): Generator
    {
        yield 'durable buffer on (nop persistent)' => [true];

        yield 'durable buffer off' => [false];
    }

    /**
     * @dataProvider durableBufferModeProvider
     */
    public function testDeferredQueueDeliversSameEventsWithDurableOnOrOff(bool $usePersistentBuffer): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client, 5000, $usePersistentBuffer);
        $queue = new DeferredOutboundEventQueue();

        $queue->enqueue(LeadEvents::LIST_POST_SAVE, [
            'segment_id' => 9,
            'properties' => [
                'name' => 'List A',
                'alias' => 'list_a',
                'operation' => 'updated',
            ],
        ]);

        $queue->enqueue(EmailEvents::EMAIL_FAILED, [
            'channel' => 'email',
            'properties' => [
                'subject' => 'Queued subject',
                'lead_id_hash' => 'hash1',
            ],
        ]);

        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertSame([
            [
                'event' => LeadEvents::LIST_POST_SAVE,
                'segment_id' => 9,
                'properties' => [
                    'name' => 'List A',
                    'alias' => 'list_a',
                    'operation' => 'updated',
                ],
            ],
            [
                'event' => EmailEvents::EMAIL_FAILED,
                'channel' => 'email',
                'properties' => [
                    'subject' => 'Queued subject',
                    'lead_id_hash' => 'hash1',
                ],
            ],
        ], $rows);
    }

    /**
     * When durable storage holds a lock and returns backlog, current-request deferred rows are merged after backlog (same as production file/redis/DB buffer).
     */
    public function testDeferredRowsMergeAfterPersistentBacklogWhenLockHeld(): void
    {
        $client = $this->createCapturingEventHttpClient();

        $integration = new Integration(true, [], InsightsApiConfig::NAME, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => 'test-token',
        ]);
        $finder = InsightsPublishedIntegrationFinder::createForTest($integration);
        $sender = new ResilientEventSender($client, new NullLogger(), 1, 0);

        $backlogRow = [
            'event' => 'mautic.insights.backlog_probe',
            'ts' => '2020-01-01T00:00:00+00:00',
            'channel' => 'test',
            'properties' => ['from' => 'persistent_store'],
        ];

        $buffer = new MauticEventBuffer(
            $sender,
            $finder,
            new InsightsHttpTokenResolver(null),
            new SeededBacklogPersistentBuffer([$backlogRow], true),
            5000,
            InsightsApiConfig::EVENT_NOTIFY_URL,
            'events',
        );

        $queue = new DeferredOutboundEventQueue();
        $queue->enqueue('mautic.insights.deferred_probe', [
            'channel' => 'test',
            'properties' => ['from' => 'deferred_queue'],
        ]);
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertSame([
            [
                'event' => 'mautic.insights.backlog_probe',
                'channel' => 'test',
                'properties' => ['from' => 'persistent_store'],
            ],
            [
                'event' => 'mautic.insights.deferred_probe',
                'channel' => 'test',
                'properties' => ['from' => 'deferred_queue'],
            ],
        ], $rows);
    }
}
