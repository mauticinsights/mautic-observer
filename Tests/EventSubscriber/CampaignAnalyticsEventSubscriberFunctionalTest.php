<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\EventSubscriber;

use DateTimeImmutable;
use DateTimeZone;
use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event as CampaignEventEntity;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\CampaignEvent;
use Mautic\CampaignBundle\Event\CampaignLeadChangeEvent;
use Mautic\CampaignBundle\Event\ExecutedBatchEvent;
use Mautic\CampaignBundle\Event\ExecutedEvent;
use Mautic\CampaignBundle\Event\FailedEvent;
use Mautic\CampaignBundle\Event\ScheduledEvent;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticInsightsBundle\EventSubscriber\CampaignAnalyticsEventSubscriber;
use MauticPlugin\MauticInsightsBundle\EventSubscriber\EventBufferFlushSubscriber;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Service\DeferredOutboundEventQueue;
use MauticPlugin\MauticInsightsBundle\Service\DisabledPersistentEventBuffer;
use MauticPlugin\MauticInsightsBundle\Service\InsightsHttpTokenResolver;
use MauticPlugin\MauticInsightsBundle\Service\InsightsPublishedIntegrationFinder;
use MauticPlugin\MauticInsightsBundle\Service\MauticEventBuffer;
use MauticPlugin\MauticInsightsBundle\Service\NoopInsightsApiSendGate;
use MauticPlugin\MauticInsightsBundle\Service\ResilientEventSender;
use MauticPlugin\MauticInsightsBundle\Tests\Support\CapturingBufferTestSupport;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Functional tests — expected rows in {@code events[]} after flush (buffer strips auto-{@code ts} where noted).
 */
final class CampaignAnalyticsEventSubscriberFunctionalTest extends TestCase
{
    use CapturingBufferTestSupport;

    public function testRegistersExpectedEvents(): void
    {
        $map = CampaignAnalyticsEventSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey(CampaignEvents::CAMPAIGN_POST_SAVE, $map);
        $this->assertArrayHasKey(CampaignEvents::CAMPAIGN_POST_DELETE, $map);
        $this->assertArrayHasKey(CampaignEvents::CAMPAIGN_ON_LEADCHANGE, $map);
        $this->assertArrayHasKey(CampaignEvents::ON_EVENT_EXECUTED, $map);
        $this->assertArrayHasKey(CampaignEvents::ON_EVENT_EXECUTED_BATCH, $map);
        $this->assertArrayHasKey(CampaignEvents::ON_EVENT_FAILED, $map);
        $this->assertArrayHasKey(CampaignEvents::ON_EVENT_SCHEDULED, $map);
    }

    public function testCampaignPostSaveAndDeletePayloadShapes(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new CampaignAnalyticsEventSubscriber($buffer, $queue);

        $campaign = new Campaign(10, 'Newsletter Q1', true);
        $subscriber->onCampaignPostSave(new CampaignEvent($campaign, true));

        $campaignUpdated = new Campaign(10, 'Newsletter Q1 rev2', false);
        $subscriber->onCampaignPostSave(new CampaignEvent($campaignUpdated, false));

        $campaignDeleted = new Campaign(10, 'Newsletter Q1 rev2');
        $subscriber->onCampaignPostDelete(new CampaignEvent($campaignDeleted, false));

        $queue->flushTo($buffer);

        $expectedCreated = [
            'event' => CampaignEvents::CAMPAIGN_POST_SAVE,
            'campaign_id' => 10,
            'properties' => [
                'name' => 'Newsletter Q1',
                'operation' => 'created',
                'is_published' => true,
            ],
        ];

        $expectedUpdated = [
            'event' => CampaignEvents::CAMPAIGN_POST_SAVE,
            'campaign_id' => 10,
            'properties' => [
                'name' => 'Newsletter Q1 rev2',
                'operation' => 'updated',
                'is_published' => false,
            ],
        ];

        $expectedDeleted = [
            'event' => CampaignEvents::CAMPAIGN_POST_DELETE,
            'campaign_id' => 10,
            'properties' => [
                'name' => 'Newsletter Q1 rev2',
            ],
        ];

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertSame([$expectedCreated, $expectedUpdated, $expectedDeleted], $rows);
    }

    /**
     * Same as Mautic {@see \Mautic\CoreBundle\Model\FormModel::deleteEntity()}: {@code getId()} is empty, {@code deletedId} holds the id.
     */
    public function testCampaignPostDeleteUsesDeletedIdWhenGetIdIsNull(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new CampaignAnalyticsEventSubscriber($buffer, $queue);

        $campaign = new Campaign(null, 'Gone campaign');
        $campaign->deletedId = 42;
        $subscriber->onCampaignPostDelete(new CampaignEvent($campaign, false));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertCount(1, $rows);
        $this->assertSame(42, $rows[0]['campaign_id']);
        $this->assertSame(CampaignEvents::CAMPAIGN_POST_DELETE, $rows[0]['event']);
    }

    /**
     * Durable buffer off: deferred campaign save + delete batch into one POST (same queue as leads; no per-enqueue HTTP).
     * Direct {@code push} handlers (lead change, step events) are not mixed here — they would otherwise precede deferred rows in the buffer.
     */
    public function testCampaignCreateAndDeleteBatchedSinglePostWhenBufferDisabled(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured[] = [
                'method' => $method,
                'url' => $url,
                'decodedBody' => json_decode((string) ($opts['body'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR),
            ];

            return new MockResponse('{}', ['http_code' => 200]);
        });

        $integration = new Integration(true, [], InsightsApiConfig::NAME, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => 'test-token',
        ]);
        $finder = InsightsPublishedIntegrationFinder::createForTest($integration);
        $sender = new ResilientEventSender($client, new NullLogger(), 1, 0);
        $queue = new DeferredOutboundEventQueue();
        $buffer = new MauticEventBuffer($sender, $finder, new InsightsHttpTokenResolver(null), new DisabledPersistentEventBuffer(), new NoopInsightsApiSendGate(), 5000);
        $subscriber = new CampaignAnalyticsEventSubscriber($buffer, $queue);

        $c = new Campaign(5, 'Batch camp');
        $subscriber->onCampaignPostSave(new CampaignEvent($c, true));

        $cDel = new Campaign(null, 'Batch camp');
        $cDel->deletedId = 5;
        $subscriber->onCampaignPostDelete(new CampaignEvent($cDel, false));

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/');
        $response = new Response();
        $flush = new EventBufferFlushSubscriber($buffer, $queue, new NoopInsightsApiSendGate());
        $flush->onKernelResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
        $flush->onKernelTerminate(new TerminateEvent($kernel, $request, $response));

        $this->assertCount(1, $captured);
        $this->assertSame('POST', $captured[0]['method']);
        $this->assertSame(InsightsApiConfig::EVENT_NOTIFY_URL, $captured[0]['url']);

        $events = $captured[0]['decodedBody']['events'];
        $this->assertCount(2, $events, 'post_save + post_delete in one batch');

        $row0 = $events[0];
        unset($row0['ts']);
        $this->assertSame([
            'event' => CampaignEvents::CAMPAIGN_POST_SAVE,
            'campaign_id' => 5,
            'properties' => [
                'name' => 'Batch camp',
                'operation' => 'created',
                'is_published' => true,
            ],
        ], $row0);

        $row1 = $events[1];
        unset($row1['ts']);
        $this->assertSame([
            'event' => CampaignEvents::CAMPAIGN_POST_DELETE,
            'campaign_id' => 5,
            'properties' => [
                'name' => 'Batch camp',
            ],
        ], $row1);
    }

    public function testCampaignLeadChangeSingleAddedAndBatchRemovedPayloadShapes(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new CampaignAnalyticsEventSubscriber($buffer, $queue);

        $campaign = new Campaign(7, 'Drip');

        $leadOne = new Lead();
        $leadOne->stubId = 100;
        $subscriber->onCampaignLeadChange(new CampaignLeadChangeEvent($campaign, 'added', $leadOne, []));

        $leadA = new Lead();
        $leadA->stubId = 201;
        $leadB = new Lead();
        $leadB->stubId = 202;
        $subscriber->onCampaignLeadChange(new CampaignLeadChangeEvent($campaign, 'removed', null, [$leadA, $leadB]));

        $expectedAdded = [
            'event' => 'campaign.added',
            'channel' => 'campaign',
            'contact_id' => 100,
            'campaign_id' => 7,
        ];

        $expectedRemovedA = [
            'event' => 'campaign.removed',
            'channel' => 'campaign',
            'contact_id' => 201,
            'campaign_id' => 7,
        ];

        $expectedRemovedB = [
            'event' => 'campaign.removed',
            'channel' => 'campaign',
            'contact_id' => 202,
            'campaign_id' => 7,
        ];

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertSame([$expectedAdded, $expectedRemovedA, $expectedRemovedB], $rows);
    }

    public function testStepExecutedSingleAndBatchWithoutLogTimestamp(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new CampaignAnalyticsEventSubscriber($buffer, $queue);

        $lead = new Lead();
        $lead->stubId = 1;
        $camp = new Campaign(2, 'C');
        $step = new CampaignEventEntity('email.send', 'Welcome mail');

        $log1 = new LeadEventLog($lead, $camp, $step, null, null);
        $subscriber->onEventExecuted(new ExecutedEvent($log1));

        $log2 = new LeadEventLog($lead, $camp, $step, null, null);
        $subscriber->onEventExecutedBatch(new ExecutedBatchEvent([$log2]));

        $expectedRow = [
            'event' => 'campaign.step_executed',
            'channel' => 'campaign',
            'contact_id' => 1,
            'campaign_id' => 2,
            'properties' => [
                'step_type' => 'email.send',
                'step_name' => 'Welcome mail',
            ],
        ];

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertSame([$expectedRow, $expectedRow], $rows);
    }

    public function testStepExecutedPayloadIncludesIsoTimestampFromDateTriggered(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new CampaignAnalyticsEventSubscriber($buffer, $queue);

        $lead = new Lead();
        $lead->stubId = 5;
        $camp = new Campaign(9, 'C9');
        $step = new CampaignEventEntity('action', 'A1');
        $when = new DateTimeImmutable('2020-06-01 14:30:00', new DateTimeZone('UTC'));

        $log = new LeadEventLog($lead, $camp, $step, $when, null);
        $subscriber->onEventExecuted(new ExecutedEvent($log));
        $buffer->flush();

        $this->assertCount(1, $this->capturedEventApiRequests);
        $this->assertSame(InsightsApiConfig::EVENT_NOTIFY_URL, $this->capturedEventApiRequests[0]['url']);

        $decoded = json_decode($this->capturedEventApiRequests[0]['options']['body'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        $row = $decoded['events'][0];

        $expected = [
            'event' => 'campaign.step_executed',
            'ts' => '2020-06-01T14:30:00+00:00',
            'channel' => 'campaign',
            'contact_id' => 5,
            'campaign_id' => 9,
            'properties' => [
                'step_type' => 'action',
                'step_name' => 'A1',
            ],
        ];

        $this->assertSame($expected, $row);
    }

    public function testStepFailedAndScheduledPayloadShapes(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new CampaignAnalyticsEventSubscriber($buffer, $queue);

        $lead = new Lead();
        $lead->stubId = 3;
        $camp = new Campaign(4, 'C4');
        $step = new CampaignEventEntity('email.send', 'Follow-up');

        $failLog = new LeadEventLog($lead, $camp, $step, null, null);
        $subscriber->onEventFailed(new FailedEvent($failLog));

        $scheduleWhen = new DateTimeImmutable('2030-12-24 08:00:00', new DateTimeZone('UTC'));
        $schedLog = new LeadEventLog($lead, $camp, $step, null, $scheduleWhen);
        $subscriber->onEventScheduled(new ScheduledEvent($schedLog, true));

        $expectedFailed = [
            'event' => 'campaign.step_failed',
            'channel' => 'campaign',
            'contact_id' => 3,
            'campaign_id' => 4,
            'properties' => [
                'step_type' => 'email.send',
                'step_name' => 'Follow-up',
            ],
        ];

        $expectedScheduled = [
            'event' => 'campaign.step_scheduled',
            'ts' => '2030-12-24T08:00:00+00:00',
            'channel' => 'campaign',
            'contact_id' => 3,
            'campaign_id' => 4,
            'properties' => [
                'step_type' => 'email.send',
                'step_name' => 'Follow-up',
                'is_reschedule' => true,
            ],
        ];

        $buffer->flush();
        $this->assertCount(1, $this->capturedEventApiRequests);
        $decoded = json_decode($this->capturedEventApiRequests[0]['options']['body'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(2, $decoded['events']);

        $rowFailed = $decoded['events'][0];
        unset($rowFailed['ts']);
        $this->assertSame($expectedFailed, $rowFailed);

        $this->assertSame($expectedScheduled, $decoded['events'][1]);
    }
}
