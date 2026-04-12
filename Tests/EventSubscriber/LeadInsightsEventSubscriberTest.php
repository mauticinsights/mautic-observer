<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\EventSubscriber;

use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Entity\Submission;
use Mautic\FormBundle\Event\SubmissionEvent;
use Mautic\FormBundle\FormEvents;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Entity\Tag;
use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\Event\ListChangeEvent;
use Mautic\LeadBundle\Event\PointsChangeEvent;
use Mautic\LeadBundle\LeadEvents;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticInsightsBundle\Buffer\DeferredOutboundEventQueue;
use MauticPlugin\MauticInsightsBundle\Buffer\DisabledPersistentEventBuffer;
use MauticPlugin\MauticInsightsBundle\Buffer\MauticEventBuffer;
use MauticPlugin\MauticInsightsBundle\EventSubscriber\EventBufferFlushSubscriber;
use MauticPlugin\MauticInsightsBundle\EventSubscriber\LeadInsightsEventSubscriber;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Service\InsightsHttpTokenResolver;
use MauticPlugin\MauticInsightsBundle\Service\InsightsPublishedIntegrationFinder;
use MauticPlugin\MauticInsightsBundle\Service\LeadInsightsContextBuilder;
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
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Full-stack functional test for {@see LeadInsightsEventSubscriber}.
 *
 * Each test fires a Mautic event, simulates kernel.response flush, and asserts
 * the exact JSON body (minus dynamic `ts`) sent to POST /api/event.
 *
 * The `events` array in every request body is the source of truth;
 * tests that need the HTTP envelope use {@see self::$capturedRequests} directly.
 */
final class LeadInsightsEventSubscriberTest extends TestCase
{
    use CapturingBufferTestSupport;

    // ──────────────────────────────────────────────────────────────
    // Fixtures & helpers
    // ──────────────────────────────────────────────────────────────

    private function makeFullLead(): Lead
    {
        $lead = new Lead();
        $lead->stubId = 42;
        $lead->stubEmail = 'jan.novak@example.com';
        $lead->stubPrimaryIdentifier = 'Jan Novák';
        $lead->stubFirstname = 'Jan';
        $lead->stubLastname = 'Novák';
        $lead->stubTags = [new Tag('vip'), new Tag('newsletter')];

        return $lead;
    }

    /**
     * Creates buffer + queue + subscriber wired together.
     * Returns [$buffer, $queue, $subscriber].
     *
     * @param array<array<string,int>> $listRows e.g. [['id' => 5], ['id' => 9]]
     *
     * @return array{MauticEventBuffer, DeferredOutboundEventQueue, LeadInsightsEventSubscriber}
     */
    private function makeStack(array $listRows = []): array
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);
        $leadModel = new LeadModel();
        $leadModel->listRows = $listRows;
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new LeadInsightsEventSubscriber($buffer, new LeadInsightsContextBuilder($leadModel), $queue);

        return [$buffer, $queue, $subscriber];
    }

    // ──────────────────────────────────────────────────────────────
    // Subscribed events map
    // ──────────────────────────────────────────────────────────────

    public function testRegistersSubscribedEvents(): void
    {
        $map = LeadInsightsEventSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(LeadEvents::LEAD_POST_SAVE, $map);
        $this->assertSame('onLeadSave', $map[LeadEvents::LEAD_POST_SAVE][0]);

        $this->assertArrayHasKey(LeadEvents::LEAD_PRE_DELETE, $map);
        $this->assertSame(['onLeadDelete', 0], $map[LeadEvents::LEAD_PRE_DELETE]);
        $this->assertArrayNotHasKey(LeadEvents::LEAD_POST_DELETE, $map);

        $this->assertArrayHasKey(LeadEvents::LEAD_POINTS_CHANGE, $map);
        $this->assertArrayHasKey(LeadEvents::LEAD_LIST_CHANGE, $map);
        $this->assertArrayHasKey(LeadEvents::LEAD_IDENTIFIED, $map);
        $this->assertArrayHasKey(FormEvents::FORM_ON_SUBMIT, $map);
    }

    // ──────────────────────────────────────────────────────────────
    // LEAD_POST_SAVE  →  contact.create
    // ──────────────────────────────────────────────────────────────

    public function testContactSaveRequestBody(): void
    {
        [$buffer, $queue, $subscriber] = $this->makeStack([['id' => 5], ['id' => 9]]);

        $subscriber->onLeadSave(new LeadEvent($this->makeFullLead(), true));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertSame([
            [
                'event' => LeadEvents::LEAD_POST_SAVE,
                'channel' => 'contact',
                'contact_id' => 42,
                'segment_ids' => [5, 9],
                'properties' => [
                    'email' => 'jan.novak@example.com',
                    'primary_identifier' => 'Jan Novák',
                    'first_name' => 'Jan',
                    'last_name' => 'Novák',
                    'tags' => ['vip', 'newsletter'],
                ],
            ],
        ], $rows);
    }

    public function testContactSaveIsQueuedViaDeferred(): void
    {
        [$buffer, $queue, $subscriber] = $this->makeStack();

        $lead = new Lead();
        $lead->stubId = 1;
        $lead->stubEmail = 'a@example.com';

        $subscriber->onLeadSave(new LeadEvent($lead, true));

        // Before flush the buffer is empty
        $this->assertSame([], $this->flushBufferAndGetNormalizedEventRows($buffer));

        // After deferred flush the event appears
        $this->createCapturingEventHttpClient(); // reset captures
        $buffer2 = $this->createMauticEventBufferForTests($this->createCapturingEventHttpClient());
        $queue->flushTo($buffer2);
        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer2);
        $this->assertCount(1, $rows);
        $this->assertSame(LeadEvents::LEAD_POST_SAVE, $rows[0]['event']);
    }

    public function testAnonymousSaveIsIgnored(): void
    {
        [$buffer, $queue, $subscriber] = $this->makeStack();

        $anon = new Lead();
        $anon->anonymous = true;
        $anon->stubId = 1;

        $subscriber->onLeadSave(new LeadEvent($anon, true));
        $queue->flushTo($buffer);

        $this->assertSame([], $this->flushBufferAndGetNormalizedEventRows($buffer));
    }

    // ──────────────────────────────────────────────────────────────
    // LEAD_PRE_DELETE  →  contact.delete
    // ──────────────────────────────────────────────────────────────

    public function testContactDeleteRequestBody(): void
    {
        [$buffer, $queue, $subscriber] = $this->makeStack([['id' => 5], ['id' => 9]]);

        $subscriber->onLeadDelete(new LeadEvent($this->makeFullLead(), false));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertSame([
            [
                'event' => LeadEvents::LEAD_PRE_DELETE,
                'channel' => 'contact',
                'contact_id' => 42,
                'segment_ids' => [5, 9],
                'properties' => [
                    'email' => 'jan.novak@example.com',
                    'primary_identifier' => 'Jan Novák',
                    'first_name' => 'Jan',
                    'last_name' => 'Novák',
                    'tags' => ['vip', 'newsletter'],
                ],
            ],
        ], $rows);
    }

    public function testDeleteContactIdCapturedAtPreDeleteTime(): void
    {
        [$buffer, $queue, $subscriber] = $this->makeStack();

        $lead = new Lead();
        $lead->stubId = 99;
        $lead->stubEmail = 'deleted@example.com';

        $subscriber->onLeadDelete(new LeadEvent($lead, false));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertSame(99, $rows[0]['contact_id'], 'contact_id must be the ID captured at PRE_DELETE time');
        $this->assertSame(LeadEvents::LEAD_PRE_DELETE, $rows[0]['event']);
    }

    public function testDeleteContactIdFallsBackToDeletedIdProperty(): void
    {
        [$buffer, $queue, $subscriber] = $this->makeStack();

        $lead = new Lead();
        $lead->stubId = 0;
        $lead->stubDeletedId = 88;
        $lead->stubEmail = 'gone@example.com';

        $subscriber->onLeadDelete(new LeadEvent($lead, false));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertSame(88, $rows[0]['contact_id']);
        $this->assertSame(LeadEvents::LEAD_PRE_DELETE, $rows[0]['event']);
    }

    public function testAnonymousDeleteIsIgnored(): void
    {
        [$buffer, $queue, $subscriber] = $this->makeStack();

        $anon = new Lead();
        $anon->anonymous = true;
        $anon->stubId = 1;

        $subscriber->onLeadDelete(new LeadEvent($anon, false));
        $queue->flushTo($buffer);

        $this->assertSame([], $this->flushBufferAndGetNormalizedEventRows($buffer));
    }

    // ──────────────────────────────────────────────────────────────
    // LEAD_POINTS_CHANGE  →  contact.points
    // ──────────────────────────────────────────────────────────────

    public function testPointsChangeRequestBody(): void
    {
        [$buffer, , $subscriber] = $this->makeStack([['id' => 3]]);

        $lead = new Lead();
        $lead->stubId = 8;
        $lead->stubEmail = 'points@example.com';
        $lead->stubFirstname = 'Pete';

        $subscriber->onPointsChange(new PointsChangeEvent($lead, 10, 25));

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertSame([
            [
                'event' => LeadEvents::LEAD_POINTS_CHANGE,
                'channel' => 'contact',
                'contact_id' => 8,
                'segment_ids' => [3],
                'properties' => [
                    'email' => 'points@example.com',
                    'first_name' => 'Pete',
                    'old_points' => 10,
                    'new_points' => 25,
                ],
            ],
        ], $rows);
    }

    public function testAnonymousPointsChangeIsIgnored(): void
    {
        [$buffer, , $subscriber] = $this->makeStack();

        $anon = new Lead();
        $anon->anonymous = true;

        $subscriber->onPointsChange(new PointsChangeEvent($anon, 0, 5));

        $this->assertSame([], $this->flushBufferAndGetNormalizedEventRows($buffer));
    }

    // ──────────────────────────────────────────────────────────────
    // LEAD_LIST_CHANGE  →  segment change
    // ──────────────────────────────────────────────────────────────

    public function testListChangeRequestBodySingleLead(): void
    {
        [$buffer, , $subscriber] = $this->makeStack();

        $lead = new Lead();
        $lead->stubId = 20;
        $lead->stubEmail = 'list@example.com';

        $list = new LeadList(77, 'Newsletter', 'newsletter', 'Newsletter');
        $subscriber->onListChange(new ListChangeEvent($lead, $list, true));

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertSame([
            [
                'event' => LeadEvents::LEAD_LIST_CHANGE,
                'channel' => 'contact',
                'contact_id' => 20,
                'properties' => [
                    'email' => 'list@example.com',
                    'list_added' => true,
                    'list_id' => 77,
                ],
            ],
        ], $rows);
    }

    public function testListChangeRequestBodyRemovedFromSegment(): void
    {
        [$buffer, , $subscriber] = $this->makeStack();

        $lead = new Lead();
        $lead->stubId = 21;
        $lead->stubEmail = 'removed@example.com';

        $list = new LeadList(88, 'VIP', 'vip', 'VIP');
        $subscriber->onListChange(new ListChangeEvent($lead, $list, false));

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertSame(LeadEvents::LEAD_LIST_CHANGE, $rows[0]['event']);
        $this->assertFalse($rows[0]['properties']['list_added']);
        $this->assertSame(88, $rows[0]['properties']['list_id']);
        $this->assertSame(21, $rows[0]['contact_id']);
    }

    public function testListChangeBatchedForMultipleLeads(): void
    {
        [$buffer, , $subscriber] = $this->makeStack();

        $l1 = new Lead();
        $l1->stubId = 1;
        $l2 = new Lead();
        $l2->stubId = 2;

        $list = new LeadList(99, 'L', 'l', 'L');
        $subscriber->onListChange(new ListChangeEvent([$l1, $l2], $list, true));

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertCount(2, $rows);
        $this->assertSame(LeadEvents::LEAD_LIST_CHANGE, $rows[0]['event']);
        $this->assertSame(1, $rows[0]['contact_id']);
        $this->assertSame(99, $rows[0]['properties']['list_id']);
        $this->assertTrue($rows[0]['properties']['list_added']);
        $this->assertSame(2, $rows[1]['contact_id']);
    }

    // ──────────────────────────────────────────────────────────────
    // LEAD_IDENTIFIED  →  contact.identified
    // ──────────────────────────────────────────────────────────────

    public function testLeadIdentifiedRequestBody(): void
    {
        [$buffer, , $subscriber] = $this->makeStack([['id' => 4]]);

        $lead = new Lead();
        $lead->stubId = 77;
        $lead->stubEmail = 'id@example.com';
        $lead->stubFirstname = 'Ida';

        $subscriber->onLeadIdentified(new LeadEvent($lead, false));

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertSame([
            [
                'event' => LeadEvents::LEAD_IDENTIFIED,
                'channel' => 'contact',
                'contact_id' => 77,
                'segment_ids' => [4],
                'properties' => [
                    'email' => 'id@example.com',
                    'first_name' => 'Ida',
                ],
            ],
        ], $rows);
    }

    public function testAnonymousLeadIdentifiedIsIgnored(): void
    {
        [$buffer, , $subscriber] = $this->makeStack();

        $anon = new Lead();
        $anon->anonymous = true;

        $subscriber->onLeadIdentified(new LeadEvent($anon, false));

        $this->assertSame([], $this->flushBufferAndGetNormalizedEventRows($buffer));
    }

    // ──────────────────────────────────────────────────────────────
    // FORM_ON_SUBMIT  →  form submit
    // ──────────────────────────────────────────────────────────────

    public function testFormSubmitRequestBody(): void
    {
        [$buffer, , $subscriber] = $this->makeStack([['id' => 2]]);

        $lead = new Lead();
        $lead->stubId = 4;
        $lead->stubEmail = 'form@example.com';
        $lead->stubFirstname = 'Frank';

        $submission = new Submission(new Form(55), $lead);
        $subscriber->onFormSubmit(new SubmissionEvent($submission));

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertSame([
            [
                'event' => FormEvents::FORM_ON_SUBMIT,
                'channel' => 'form',
                'properties' => [
                    'form_id' => 55,
                    'email' => 'form@example.com',
                    'first_name' => 'Frank',
                ],
                'contact_id' => 4,
                'segment_ids' => [2],
            ],
        ], $rows);
    }

    public function testFormSubmitWithoutLeadOmitsContactFields(): void
    {
        [$buffer, , $subscriber] = $this->makeStack();

        $submission = new Submission(new Form(33), null);
        $subscriber->onFormSubmit(new SubmissionEvent($submission));

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertSame([
            [
                'event' => FormEvents::FORM_ON_SUBMIT,
                'channel' => 'form',
                'properties' => [
                    'form_id' => 33,
                ],
            ],
        ], $rows);
    }

    // ──────────────────────────────────────────────────────────────
    // Batching — multiple events in one POST
    // ──────────────────────────────────────────────────────────────

    public function testMultipleEventsAreBatchedInSinglePost(): void
    {
        [$buffer, $queue, $subscriber] = $this->makeStack([['id' => 5], ['id' => 9]]);

        $leadA = new Lead();
        $leadA->stubId = 1;
        $leadA->stubEmail = 'alice@example.com';
        $leadA->stubFirstname = 'Alice';

        $leadB = new Lead();
        $leadB->stubId = 2;
        $leadB->stubEmail = 'bob@example.com';
        $leadB->stubFirstname = 'Bob';

        // create leadA, create leadB, delete leadA — all in the same lifecycle
        $subscriber->onLeadSave(new LeadEvent($leadA, true));
        $subscriber->onLeadSave(new LeadEvent($leadB, true));
        $subscriber->onLeadDelete(new LeadEvent($leadA, false));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertCount(3, $rows, 'All three events must be in a single batch');

        $this->assertSame(LeadEvents::LEAD_POST_SAVE, $rows[0]['event']);
        $this->assertSame(1, $rows[0]['contact_id']);
        $this->assertSame('alice@example.com', $rows[0]['properties']['email']);

        $this->assertSame(LeadEvents::LEAD_POST_SAVE, $rows[1]['event']);
        $this->assertSame(2, $rows[1]['contact_id']);
        $this->assertSame('bob@example.com', $rows[1]['properties']['email']);

        $this->assertSame(LeadEvents::LEAD_PRE_DELETE, $rows[2]['event']);
        $this->assertSame(1, $rows[2]['contact_id']);
        $this->assertSame('alice@example.com', $rows[2]['properties']['email']);
    }

    public function testMixedEventsAllBatchedWithBufferDisabled(): void
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

        $leadModel = new LeadModel();
        $leadModel->listRows = [];
        $queue = new DeferredOutboundEventQueue();
        $buffer = new MauticEventBuffer($sender, $finder, new InsightsHttpTokenResolver(null), new DisabledPersistentEventBuffer(), new NoopInsightsApiSendGate(), 5000);
        $subscriber = new LeadInsightsEventSubscriber($buffer, new LeadInsightsContextBuilder($leadModel), $queue);

        $leadA = new Lead();
        $leadA->stubId = 10;
        $leadA->stubEmail = 'a@example.com';

        $leadB = new Lead();
        $leadB->stubId = 11;
        $leadB->stubEmail = 'b@example.com';

        $leadC = new Lead();
        $leadC->stubId = 12;
        $leadC->stubEmail = 'c@example.com';

        $subscriber->onLeadSave(new LeadEvent($leadA, true));
        $subscriber->onLeadSave(new LeadEvent($leadB, true));
        $subscriber->onLeadDelete(new LeadEvent($leadC, false));

        $kernel = $this->createMock(HttpKernelInterface::class);
        $flushSubscriber = new EventBufferFlushSubscriber($buffer, $queue, new NoopInsightsApiSendGate());
        $flushSubscriber->onKernelResponse(
            new ResponseEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST, new Response())
        );

        $this->assertCount(1, $captured, 'Buffer OFF: all events must be batched into a single POST on flush');
        $this->assertSame('POST', $captured[0]['method']);
        $this->assertSame(InsightsApiConfig::EVENT_NOTIFY_URL, $captured[0]['url']);

        $events = $captured[0]['decodedBody']['events'];
        $this->assertCount(3, $events);
        $this->assertSame(LeadEvents::LEAD_POST_SAVE, $events[0]['event']);
        $this->assertSame(10, $events[0]['contact_id']);
        $this->assertSame(LeadEvents::LEAD_POST_SAVE, $events[1]['event']);
        $this->assertSame(11, $events[1]['contact_id']);
        $this->assertSame(LeadEvents::LEAD_PRE_DELETE, $events[2]['event']);
        $this->assertSame(12, $events[2]['contact_id']);
    }

    // ──────────────────────────────────────────────────────────────
    // Deferred queue — ordering guarantees
    // ──────────────────────────────────────────────────────────────

    public function testDeferredQueuePreservesEnqueueOrder(): void
    {
        [$buffer, $queue, $subscriber] = $this->makeStack();

        $newLead = new Lead();
        $newLead->stubId = 5;
        $newLead->stubEmail = 'n@example.com';

        $delLead = new Lead();
        $delLead->stubId = 6;
        $delLead->stubEmail = 'd@example.com';

        $subscriber->onLeadDelete(new LeadEvent($delLead, false));
        $subscriber->onLeadSave(new LeadEvent($newLead, true));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertSame(LeadEvents::LEAD_PRE_DELETE, $rows[0]['event'], 'Delete enqueued first must arrive first');
        $this->assertSame(LeadEvents::LEAD_POST_SAVE, $rows[1]['event']);
    }

    // ──────────────────────────────────────────────────────────────
    // Token not yet available at push time → sent on flush
    // ──────────────────────────────────────────────────────────────

    public function testEventSentOnFlushWhenTokenUnavailableAtPushTime(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured[] = json_decode((string) ($opts['body'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

            return new MockResponse('{}', ['http_code' => 200]);
        });

        // Integration starts unpublished → no token during push
        $integration = new Integration(false, [], InsightsApiConfig::NAME, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => 'test-token',
        ]);
        $finder = InsightsPublishedIntegrationFinder::createForTest($integration);
        $sender = new ResilientEventSender($client, new NullLogger(), 1, 0);

        $leadModel = new LeadModel();
        $leadModel->listRows = [['id' => 3]];
        $queue = new DeferredOutboundEventQueue();
        $buffer = new MauticEventBuffer($sender, $finder, new InsightsHttpTokenResolver(null), new DisabledPersistentEventBuffer(), new NoopInsightsApiSendGate(), 5000);
        $subscriber = new LeadInsightsEventSubscriber($buffer, new LeadInsightsContextBuilder($leadModel), $queue);

        $subscriber->onLeadSave(new LeadEvent($this->makeFullLead(), true));

        // Integration becomes published before flush
        $integration->makePublished();

        $kernel = $this->createMock(HttpKernelInterface::class);
        (new EventBufferFlushSubscriber($buffer, $queue, new NoopInsightsApiSendGate()))->onKernelResponse(
            new ResponseEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST, new Response())
        );

        $this->assertCount(1, $captured, 'Event must be sent on flush even if token was unavailable at push time');
        $this->assertArrayHasKey('events', $captured[0]);
        $this->assertSame(42, $captured[0]['events'][0]['contact_id']);
        $this->assertSame(LeadEvents::LEAD_POST_SAVE, $captured[0]['events'][0]['event']);
    }
}
