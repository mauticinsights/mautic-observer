<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\EventSubscriber;

use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Event\LeadListEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\MauticInsightsBundle\EventSubscriber\EventBufferFlushSubscriber;
use MauticPlugin\MauticInsightsBundle\EventSubscriber\SegmentInsightsEventSubscriber;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Service\DeferredOutboundEventQueue;
use MauticPlugin\MauticInsightsBundle\Tests\Support\CapturingBufferTestSupport;
use PHPUnit\Framework\TestCase;

/**
 * Full-stack functional test for {@see SegmentInsightsEventSubscriber}.
 *
 * Saves/updates/deletes → shared {@see DeferredOutboundEventQueue}, then POST {@see InsightsApiConfig::EVENT_NOTIFY_URL}, root key `events` (same shape as campaign CRUD).
 */
final class SegmentInsightsEventSubscriberFunctionalTest extends TestCase
{
    use CapturingBufferTestSupport;

    public function testRegistersExpectedEvents(): void
    {
        $map = SegmentInsightsEventSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey(LeadEvents::LIST_POST_SAVE, $map);
        $this->assertArrayHasKey(LeadEvents::LIST_POST_DELETE, $map);
    }

    public function testSegmentCreatedRequestBody(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new SegmentInsightsEventSubscriber($queue);

        $list = new LeadList(10, 'VIP', 'vip', 'VIP segment');
        $subscriber->onListPostSave(new LeadListEvent($list, true));

        $queue->flushTo($buffer);
        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertSame([
            [
                'event' => LeadEvents::LIST_POST_SAVE,
                'segment_id' => 10,
                'properties' => [
                    'name' => 'VIP',
                    'alias' => 'vip',
                    'operation' => 'created',
                    'public_name' => 'VIP segment',
                    'is_published' => true,
                ],
            ],
        ], $rows);
    }

    public function testSegmentUpdatedRequestBody(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new SegmentInsightsEventSubscriber($queue);

        $list = new LeadList(10, 'VIP Gold', 'vip', 'VIP segment', false);
        $subscriber->onListPostSave(new LeadListEvent($list, false));

        $queue->flushTo($buffer);
        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertSame([
            [
                'event' => LeadEvents::LIST_POST_SAVE,
                'segment_id' => 10,
                'properties' => [
                    'name' => 'VIP Gold',
                    'alias' => 'vip',
                    'operation' => 'updated',
                    'public_name' => 'VIP segment',
                    'is_published' => false,
                ],
            ],
        ], $rows);
    }

    public function testSegmentSaveOmitsEmptyPublicName(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new SegmentInsightsEventSubscriber($queue);

        $list = new LeadList(5, 'Internal', 'internal', null);
        $subscriber->onListPostSave(new LeadListEvent($list, true));

        $queue->flushTo($buffer);
        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertSame([
            [
                'event' => LeadEvents::LIST_POST_SAVE,
                'segment_id' => 5,
                'properties' => [
                    'name' => 'Internal',
                    'alias' => 'internal',
                    'operation' => 'created',
                    'is_published' => true,
                ],
            ],
        ], $rows);
    }

    public function testSegmentDeleteRequestBody(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new SegmentInsightsEventSubscriber($queue);

        $list = new LeadList(10, 'VIP Gold', 'vip', null);
        $subscriber->onListPostDelete(new LeadListEvent($list, false));

        $queue->flushTo($buffer);
        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertSame([
            [
                'event' => LeadEvents::LIST_POST_DELETE,
                'segment_id' => 10,
                'properties' => [
                    'name' => 'VIP Gold',
                    'operation' => 'deleted',
                ],
            ],
        ], $rows);
    }

    /**
     * Mautic {@see \Mautic\CoreBundle\Model\FormModel::deleteEntity()} clears {@code getId()} and sets {@code deletedId} before LIST_POST_DELETE.
     */
    public function testSegmentDeleteUsesDeletedIdWhenGetIdIsNull(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new SegmentInsightsEventSubscriber($queue);

        $list = new LeadList(null, 'Gone', 'gone', null);
        $list->deletedId = 42;

        $subscriber->onListPostDelete(new LeadListEvent($list, false));

        $queue->flushTo($buffer);
        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertSame(42, $rows[0]['segment_id']);
    }

    public function testSegmentSaveUsesPostToEventApi(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new SegmentInsightsEventSubscriber($queue);

        $list = new LeadList(7, 'News', 'news', null);
        $subscriber->onListPostSave(new LeadListEvent($list, true));

        $queue->flushTo($buffer);
        $buffer->flush();

        $this->assertCount(1, $this->capturedEventApiRequests);
        $this->assertSame('POST', $this->capturedEventApiRequests[0]['method']);
        $this->assertSame(InsightsApiConfig::EVENT_NOTIFY_URL, $this->capturedEventApiRequests[0]['url']);
    }

    public function testSaveAndDeleteBothDeferredToTerminateInOneBatch(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new SegmentInsightsEventSubscriber($queue);

        $listSave = new LeadList(1, 'Active', 'active', null);
        $listDel = new LeadList(2, 'Gone', 'gone', null);

        $subscriber->onListPostSave(new LeadListEvent($listSave, true));
        $subscriber->onListPostDelete(new LeadListEvent($listDel, false));

        $this->assertCount(0, $this->capturedEventApiRequests);

        $flushSubscriber = new EventBufferFlushSubscriber($buffer, $queue);
        $flushSubscriber->onKernelTerminate($this->newMainRequestTerminateEvent());

        $this->assertCount(1, $this->capturedEventApiRequests);
        $this->assertSame('POST', $this->capturedEventApiRequests[0]['method']);
        $this->assertSame(InsightsApiConfig::EVENT_NOTIFY_URL, $this->capturedEventApiRequests[0]['url']);

        $decoded = json_decode($this->capturedEventApiRequests[0]['options']['body'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('events', $decoded);
        $this->assertCount(2, $decoded['events']);

        $e0 = $decoded['events'][0];
        $e1 = $decoded['events'][1];
        unset($e0['ts'], $e1['ts']);

        $this->assertSame(LeadEvents::LIST_POST_SAVE, $e0['event']);
        $this->assertSame(1, $e0['segment_id']);
        $this->assertTrue($e0['properties']['is_published']);

        $this->assertSame(LeadEvents::LIST_POST_DELETE, $e1['event']);
        $this->assertSame(2, $e1['segment_id']);
        $this->assertSame('deleted', $e1['properties']['operation']);
    }
}
