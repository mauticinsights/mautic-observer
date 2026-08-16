<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\EventSubscriber;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Entity\Tag;
use Mautic\LeadBundle\Event\ChannelSubscriptionChange;
use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\Event\LeadMergeEvent;
use Mautic\LeadBundle\Event\ListChangeEvent;
use Mautic\LeadBundle\Event\PointsChangeEvent;
use Mautic\LeadBundle\LeadEvents;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticInsightsBundle\Buffer\DeferredOutboundEventQueue;
use MauticPlugin\MauticInsightsBundle\Buffer\DisabledPersistentEventBuffer;
use MauticPlugin\MauticInsightsBundle\Buffer\MauticEventBuffer;
use MauticPlugin\MauticInsightsBundle\Event\InsightsLeadPseudoEvents;
use MauticPlugin\MauticInsightsBundle\EventSubscriber\EventBufferFlushSubscriber;
use MauticPlugin\MauticInsightsBundle\EventSubscriber\LeadInsightsEventSubscriber;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Service\InsightsHttpTokenResolver;
use MauticPlugin\MauticInsightsBundle\Service\InsightsOutboundTokenProvider;
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
        $lead->stubPoints = 42;

        return $lead;
    }

    /** Lead state after {@see \Mautic\CoreBundle\Model\FormModel::deleteEntity}: {@code deletedId} set, OR id cleared. */
    private function makeFullLeadPostDeleted(): Lead
    {
        $lead = $this->makeFullLead();
        $id = $lead->getId();
        $lead->stubId = null;
        $lead->deletedId = $id;

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
        $subscriber = new LeadInsightsEventSubscriber(
            $buffer,
            new LeadInsightsContextBuilder($leadModel),
            $queue,
            $this->createActiveTestOutboundTokenProvider()
        );

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

        $this->assertArrayHasKey(LeadEvents::LEAD_POST_DELETE, $map);
        $this->assertSame(['onLeadDelete', 0], $map[LeadEvents::LEAD_POST_DELETE]);

        $this->assertArrayHasKey(LeadEvents::LEAD_LIST_CHANGE, $map);
        $this->assertArrayHasKey(LeadEvents::LEAD_IDENTIFIED, $map);
        $this->assertArrayHasKey(LeadEvents::CHANNEL_SUBSCRIPTION_CHANGED, $map);
        $this->assertSame(['onChannelSubscriptionChanged', 0], $map[LeadEvents::CHANNEL_SUBSCRIPTION_CHANGED]);

        $this->assertArrayHasKey(LeadEvents::LEAD_POINTS_CHANGE, $map);
        $this->assertSame('onPointsChange', $map[LeadEvents::LEAD_POINTS_CHANGE][0]);
    }

    // ──────────────────────────────────────────────────────────────
    // LEAD_POST_SAVE  →  contact.create
    // ──────────────────────────────────────────────────────────────

    public function testContactSaveRequestBodyForNewContact(): void
    {
        [$buffer, $queue, $subscriber] = $this->makeStack([['id' => 5], ['id' => 9]]);

        $subscriber->onLeadSave(new LeadEvent($this->makeFullLead(), isNew: true));
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
                    'points' => 42,
                    'is_new' => true,
                ],
            ],
        ], $rows);
    }

    public function testContactSaveRequestBodyForUpdateDoesNotIncludeIsNew(): void
    {
        [$buffer, $queue, $subscriber] = $this->makeStack([['id' => 5], ['id' => 9]]);

        $subscriber->onLeadSave(new LeadEvent($this->makeFullLead(), isNew: false));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertCount(1, $rows);
        $this->assertSame(LeadEvents::LEAD_POST_SAVE, $rows[0]['event']);
        $this->assertArrayNotHasKey('is_new', $rows[0]['properties'] ?? []);
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
    // LEAD_POST_DELETE  →  contact.delete (after DB delete)
    // ──────────────────────────────────────────────────────────────

    public function testContactDeleteRequestBody(): void
    {
        [$buffer, $queue, $subscriber] = $this->makeStack([['id' => 5], ['id' => 9]]);

        $subscriber->onLeadDelete(new LeadEvent($this->makeFullLeadPostDeleted(), false));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertSame([
            [
                'event' => LeadEvents::LEAD_POST_DELETE,
                'channel' => 'contact',
                'contact_id' => 42,
                'segment_ids' => [5, 9],
                'properties' => [
                    'email' => 'jan.novak@example.com',
                    'primary_identifier' => 'Jan Novák',
                    'first_name' => 'Jan',
                    'last_name' => 'Novák',
                    'tags' => ['vip', 'newsletter'],
                    'points' => 42,
                ],
            ],
        ], $rows);
    }

    public function testDeleteContactIdUsesFormEntityDeletedId(): void
    {
        [$buffer, $queue, $subscriber] = $this->makeStack();

        $lead = new Lead();
        $lead->stubId = null;
        $lead->deletedId = 99;
        $lead->stubEmail = 'deleted@example.com';

        $subscriber->onLeadDelete(new LeadEvent($lead, false));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertSame(99, $rows[0]['contact_id'], 'contact_id must match FormEntity::$deletedId (audit log objectId)');
        $this->assertSame(LeadEvents::LEAD_POST_DELETE, $rows[0]['event']);
    }

    public function testDeleteContactIdFallsBackToGetDeletedIdWhenPropertyUnset(): void
    {
        [$buffer, $queue, $subscriber] = $this->makeStack();

        $lead = new Lead();
        $lead->stubId = null;
        $lead->stubDeletedId = 88;
        $lead->stubEmail = 'gone@example.com';

        $subscriber->onLeadDelete(new LeadEvent($lead, false));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertSame(88, $rows[0]['contact_id']);
        $this->assertSame(LeadEvents::LEAD_POST_DELETE, $rows[0]['event']);
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
    // LEAD_POST_MERGE  →  contact merge
    // ──────────────────────────────────────────────────────────────

    public function testContactMergeRequestBody(): void
    {
        [$buffer, $queue, $subscriber] = $this->makeStack([['id' => 5], ['id' => 9]]);

        $loser = $this->makeFullLead();
        $winner = new Lead();
        $winner->stubId = 10;
        $winner->stubEmail = 'winner@example.com';

        $subscriber->onLeadMerge(new LeadMergeEvent($winner, $loser));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertSame([
            [
                'event' => LeadEvents::LEAD_POST_MERGE,
                'channel' => 'contact',
                'contact_id' => 42,
                'segment_ids' => [5, 9],
                'properties' => [
                    'email' => 'jan.novak@example.com',
                    'primary_identifier' => 'Jan Novák',
                    'first_name' => 'Jan',
                    'last_name' => 'Novák',
                    'tags' => ['vip', 'newsletter'],
                    'points' => 42,
                    'survivor_id' => 10,
                ],
            ],
        ], $rows);
    }

    public function testMergeLoserIdIsTopLevelContactId(): void
    {
        [$buffer, $queue, $subscriber] = $this->makeStack();

        $loser = new Lead();
        $loser->stubId = 77;
        $loser->stubEmail = 'loser@example.com';
        $winner = new Lead();
        $winner->stubId = 99;

        $subscriber->onLeadMerge(new LeadMergeEvent($winner, $loser));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertSame(77, $rows[0]['contact_id'], 'contact_id must be the loser (merged-away) contact id');
        $this->assertSame(99, $rows[0]['properties']['survivor_id'], 'survivor_id must be the winner id');
        $this->assertSame(LeadEvents::LEAD_POST_MERGE, $rows[0]['event']);
    }

    public function testAnonymousMergeLoserIsIgnored(): void
    {
        [$buffer, $queue, $subscriber] = $this->makeStack();

        $anonLoser = new Lead();
        $anonLoser->anonymous = true;
        $anonLoser->stubId = 1;
        $winner = new Lead();
        $winner->stubId = 2;

        $subscriber->onLeadMerge(new LeadMergeEvent($winner, $anonLoser));
        $queue->flushTo($buffer);

        $this->assertSame([], $this->flushBufferAndGetNormalizedEventRows($buffer));
    }

    public function testMergeLoserWithMinimalPropertiesEmitsEvent(): void
    {
        [$buffer, $queue, $subscriber] = $this->makeStack();

        $loser = new Lead();
        $loser->stubId = 55;
        $loser->stubEmail = 'minimal@example.com';
        $winner = new Lead();
        $winner->stubId = 66;

        $subscriber->onLeadMerge(new LeadMergeEvent($winner, $loser));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertCount(1, $rows);
        $this->assertSame(55, $rows[0]['contact_id']);
        $this->assertSame(LeadEvents::LEAD_POST_MERGE, $rows[0]['event']);
        $this->assertSame(66, $rows[0]['properties']['survivor_id']);
        $this->assertArrayHasKey('email', $rows[0]['properties']);
        $this->assertSame('minimal@example.com', $rows[0]['properties']['email']);
    }

    public function testMergeWithNullWinnerIdEmitsEventWithoutSurvivorId(): void
    {
        [$buffer, $queue, $subscriber] = $this->makeStack();

        $loser = new Lead();
        $loser->stubId = 77;
        $loser->stubEmail = 'lonely@example.com';
        $winner = new Lead();

        $subscriber->onLeadMerge(new LeadMergeEvent($winner, $loser));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertCount(1, $rows);
        $this->assertSame(77, $rows[0]['contact_id']);
        $this->assertSame(LeadEvents::LEAD_POST_MERGE, $rows[0]['event']);
        // survivor_id is emitted but null when the winner has no id
        $this->assertArrayHasKey('survivor_id', $rows[0]['properties']);
        $this->assertNull($rows[0]['properties']['survivor_id']);
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
        // Explicit zero-score coverage: 0 must be emitted (not filtered as empty) because
        // "contact has no points yet" is a meaningful analytical state, distinct from "points unknown".
        $lead->stubPoints = 0;

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
                    'points' => 0,
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
    // LEAD_POINTS_CHANGE  →  score delta
    // ──────────────────────────────────────────────────────────────

    public function testLeadPointsChangeRequestBody(): void
    {
        [$buffer, , $subscriber] = $this->makeStack([['id' => 5]]);

        $lead = new Lead();
        $lead->stubId = 42;
        $lead->stubEmail = 'score@example.com';
        $lead->stubFirstname = 'Scorer';
        $lead->stubPoints = 55;

        $subscriber->onPointsChange(new PointsChangeEvent($lead, 50, 55));

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertSame([
            [
                'event' => LeadEvents::LEAD_POINTS_CHANGE,
                'channel' => 'contact',
                'contact_id' => 42,
                'segment_ids' => [5],
                'properties' => [
                    'email' => 'score@example.com',
                    'first_name' => 'Scorer',
                    'points' => 55,
                    'old_points' => 50,
                    'new_points' => 55,
                    'delta' => 5,
                ],
            ],
        ], $rows);
    }

    public function testLeadPointsChangeNegativeDelta(): void
    {
        [$buffer, , $subscriber] = $this->makeStack([]);

        $lead = new Lead();
        $lead->stubId = 77;
        $lead->stubEmail = 'decay@example.com';
        $lead->stubPoints = 30;

        $subscriber->onPointsChange(new PointsChangeEvent($lead, 40, 30));

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertCount(1, $rows);
        $this->assertSame(LeadEvents::LEAD_POINTS_CHANGE, $rows[0]['event']);
        $this->assertSame(40, $rows[0]['properties']['old_points']);
        $this->assertSame(30, $rows[0]['properties']['new_points']);
        $this->assertSame(-10, $rows[0]['properties']['delta']);
    }

    public function testAnonymousPointsChangeIsIgnored(): void
    {
        [$buffer, , $subscriber] = $this->makeStack([]);

        $anon = new Lead();
        $anon->anonymous = true;

        $subscriber->onPointsChange(new PointsChangeEvent($anon, 0, 10));

        $this->assertSame([], $this->flushBufferAndGetNormalizedEventRows($buffer));
    }

    // ──────────────────────────────────────────────────────────────
    // CHANNEL_SUBSCRIPTION_CHANGED  →  DNC status transition per channel
    //
    // Status codes mirror Mautic\LeadBundle\Entity\DoNotContact:
    //   0 = contactable, 1 = unsubscribed, 2 = bounced, 3 = manual.
    // Top-level `channel` passes through the DNC channel (email / sms / …),
    // and both the int and verb forms of old/new status are emitted for
    // analytics ergonomics (int stable, verb human-readable).
    // ──────────────────────────────────────────────────────────────

    public function testChannelSubscriptionChangedRequestBodyEmailUnsubscribe(): void
    {
        [$buffer, , $subscriber] = $this->makeStack([['id' => 5]]);

        $lead = new Lead();
        $lead->stubId = 100;
        $lead->stubEmail = 'unsub@example.com';
        $lead->stubFirstname = 'Una';
        $lead->stubPoints = 12;

        $subscriber->onChannelSubscriptionChanged(
            new ChannelSubscriptionChange($lead, 'email', 0, 1)
        );

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertSame([
            [
                'event' => LeadEvents::CHANNEL_SUBSCRIPTION_CHANGED,
                'channel' => 'email',
                'contact_id' => 100,
                'segment_ids' => [5],
                'properties' => [
                    'email' => 'unsub@example.com',
                    'first_name' => 'Una',
                    'points' => 12,
                    'dnc_old_status' => 0,
                    'dnc_new_status' => 1,
                    'dnc_old_status_verb' => 'contactable',
                    'dnc_new_status_verb' => 'unsubscribed',
                ],
            ],
        ], $rows);
    }

    public function testChannelSubscriptionChangedForBouncedEmail(): void
    {
        [$buffer, , $subscriber] = $this->makeStack();

        $lead = new Lead();
        $lead->stubId = 101;
        $lead->stubEmail = 'bounce@example.com';

        $subscriber->onChannelSubscriptionChanged(
            new ChannelSubscriptionChange($lead, 'email', 0, 2)
        );

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertCount(1, $rows);
        $this->assertSame(LeadEvents::CHANNEL_SUBSCRIPTION_CHANGED, $rows[0]['event']);
        $this->assertSame('email', $rows[0]['channel']);
        $this->assertSame(2, $rows[0]['properties']['dnc_new_status']);
        $this->assertSame('bounced', $rows[0]['properties']['dnc_new_status_verb']);
    }

    public function testChannelSubscriptionChangedForManualSmsOverride(): void
    {
        [$buffer, , $subscriber] = $this->makeStack();

        $lead = new Lead();
        $lead->stubId = 102;
        $lead->stubEmail = 'sms@example.com';

        $subscriber->onChannelSubscriptionChanged(
            new ChannelSubscriptionChange($lead, 'sms', 0, 3)
        );

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertCount(1, $rows);
        $this->assertSame('sms', $rows[0]['channel'], 'Top-level channel must pass through the DNC channel unchanged');
        $this->assertSame(3, $rows[0]['properties']['dnc_new_status']);
        $this->assertSame('manual', $rows[0]['properties']['dnc_new_status_verb']);
    }

    public function testChannelSubscriptionResubscribeEmitsContactableVerb(): void
    {
        [$buffer, , $subscriber] = $this->makeStack();

        $lead = new Lead();
        $lead->stubId = 103;
        $lead->stubEmail = 'resub@example.com';

        // 1 → 0 resubscribe (e.g. user clicked "Resubscribe" on preference center).
        $subscriber->onChannelSubscriptionChanged(
            new ChannelSubscriptionChange($lead, 'email', 1, 0)
        );

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertSame('unsubscribed', $rows[0]['properties']['dnc_old_status_verb']);
        $this->assertSame('contactable', $rows[0]['properties']['dnc_new_status_verb']);
        $this->assertSame(1, $rows[0]['properties']['dnc_old_status']);
        $this->assertSame(0, $rows[0]['properties']['dnc_new_status']);
    }

    public function testAnonymousChannelSubscriptionChangedIsIgnored(): void
    {
        [$buffer, , $subscriber] = $this->makeStack();

        $anon = new Lead();
        $anon->anonymous = true;

        $subscriber->onChannelSubscriptionChanged(
            new ChannelSubscriptionChange($anon, 'email', 0, 1)
        );

        $this->assertSame([], $this->flushBufferAndGetNormalizedEventRows($buffer));
    }

    // ──────────────────────────────────────────────────────────────
    // LEAD_POST_SAVE → pseudo tag / stage events (Insights-only names)
    // ──────────────────────────────────────────────────────────────

    public function testLeadPostSaveWithTagDiffEmitsTagsPseudoAfterPostSave(): void
    {
        [$buffer, $queue, $subscriber] = $this->makeStack([['id' => 5], ['id' => 9]]);
        $lead = $this->makeFullLead();
        $lead->stubChanges = [
            'tags' => ['added' => ['trial'], 'removed' => ['newsletter']],
        ];

        $subscriber->onLeadSave(new LeadEvent($lead, false));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertCount(2, $rows);
        $this->assertSame(LeadEvents::LEAD_POST_SAVE, $rows[0]['event']);
        $this->assertSame(InsightsLeadPseudoEvents::LEAD_TAGS_CHANGE, $rows[1]['event']);
        $this->assertSame(42, $rows[1]['contact_id']);
        $this->assertSame(['trial'], $rows[1]['properties']['tags_added']);
        $this->assertSame(['newsletter'], $rows[1]['properties']['tags_removed']);
    }

    public function testLeadPostSaveWithStageDiffEmitsStagePseudoAfterPostSave(): void
    {
        [$buffer, $queue, $subscriber] = $this->makeStack([['id' => 5]]);
        $lead = $this->makeFullLead();
        $lead->stubChanges = ['stage' => [1, 2]];

        $subscriber->onLeadSave(new LeadEvent($lead, false));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertCount(2, $rows);
        $this->assertSame(LeadEvents::LEAD_POST_SAVE, $rows[0]['event']);
        $this->assertSame(InsightsLeadPseudoEvents::LEAD_STAGE_CHANGE, $rows[1]['event']);
        $this->assertSame(1, $rows[1]['properties']['stage_old_id']);
        $this->assertSame(2, $rows[1]['properties']['stage_new_id']);
    }

    public function testLeadPostSaveWithTagsAndStageEmitsPostSaveThenBothPseudosInOrder(): void
    {
        [$buffer, $queue, $subscriber] = $this->makeStack([]);
        $lead = $this->makeFullLead();
        $lead->stubChanges = [
            'tags' => ['added' => ['x'], 'removed' => []],
            'stage' => [10, 20],
        ];

        $subscriber->onLeadSave(new LeadEvent($lead, false));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertCount(3, $rows);
        $this->assertSame(LeadEvents::LEAD_POST_SAVE, $rows[0]['event']);
        $this->assertSame(InsightsLeadPseudoEvents::LEAD_TAGS_CHANGE, $rows[1]['event']);
        $this->assertSame(InsightsLeadPseudoEvents::LEAD_STAGE_CHANGE, $rows[2]['event']);
    }

    public function testLeadPostSaveWithEmptyTagDiffDoesNotEmitTagsPseudo(): void
    {
        [$buffer, $queue, $subscriber] = $this->makeStack([]);
        $lead = $this->makeFullLead();
        $lead->stubChanges = ['tags' => ['added' => [], 'removed' => []]];

        $subscriber->onLeadSave(new LeadEvent($lead, false));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertCount(1, $rows);
        $this->assertSame(LeadEvents::LEAD_POST_SAVE, $rows[0]['event']);
    }

    public function testLeadPostSaveWithNoopStageIdsDoesNotEmitStagePseudo(): void
    {
        [$buffer, $queue, $subscriber] = $this->makeStack([]);
        $lead = $this->makeFullLead();
        $lead->stubChanges = ['stage' => [3, 3]];

        $subscriber->onLeadSave(new LeadEvent($lead, false));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertCount(1, $rows);
        $this->assertSame(LeadEvents::LEAD_POST_SAVE, $rows[0]['event']);
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
        $leadA->stubId = null;
        $leadA->deletedId = 1;
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

        $this->assertSame(LeadEvents::LEAD_POST_DELETE, $rows[2]['event']);
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
        $tokenProvider = new InsightsOutboundTokenProvider($finder, new InsightsHttpTokenResolver(null), null);
        $sender = new ResilientEventSender($client, new NullLogger(), 1, 0);

        $leadModel = new LeadModel();
        $leadModel->listRows = [];
        $queue = new DeferredOutboundEventQueue();
        $buffer = new MauticEventBuffer($sender, $tokenProvider, new DisabledPersistentEventBuffer(), new NoopInsightsApiSendGate(), 5000);
        $subscriber = new LeadInsightsEventSubscriber(
            $buffer,
            new LeadInsightsContextBuilder($leadModel),
            $queue,
            $tokenProvider
        );

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
        $leadC->stubId = null;
        $leadC->deletedId = 12;
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
        $this->assertSame(LeadEvents::LEAD_POST_DELETE, $events[2]['event']);
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
        $delLead->stubId = null;
        $delLead->deletedId = 6;
        $delLead->stubEmail = 'd@example.com';

        $subscriber->onLeadDelete(new LeadEvent($delLead, false));
        $subscriber->onLeadSave(new LeadEvent($newLead, true));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertSame(LeadEvents::LEAD_POST_DELETE, $rows[0]['event'], 'Delete enqueued first must arrive first');
        $this->assertSame(LeadEvents::LEAD_POST_SAVE, $rows[1]['event']);
    }

    // ──────────────────────────────────────────────────────────────
    // Outbound disabled (unpublished integration) — no buffer, no HTTP
    // ──────────────────────────────────────────────────────────────

    public function testNothingBufferedOrSentWhenIntegrationUnpublished(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured[] = json_decode((string) ($opts['body'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

            return new MockResponse('{}', ['http_code' => 200]);
        });

        $integration = new Integration(false, [], InsightsApiConfig::NAME, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => 'test-token',
        ]);
        $finder = InsightsPublishedIntegrationFinder::createForTest($integration);
        $tokenProvider = new InsightsOutboundTokenProvider($finder, new InsightsHttpTokenResolver(null), null);
        $sender = new ResilientEventSender($client, new NullLogger(), 1, 0);

        $leadModel = new LeadModel();
        $leadModel->listRows = [['id' => 3]];
        $queue = new DeferredOutboundEventQueue();
        $buffer = new MauticEventBuffer($sender, $tokenProvider, new DisabledPersistentEventBuffer(), new NoopInsightsApiSendGate(), 5000);
        $subscriber = new LeadInsightsEventSubscriber(
            $buffer,
            new LeadInsightsContextBuilder($leadModel),
            $queue,
            $tokenProvider
        );

        $subscriber->onLeadSave(new LeadEvent($this->makeFullLead(), true));
        $integration->makePublished();

        $kernel = $this->createMock(HttpKernelInterface::class);
        (new EventBufferFlushSubscriber($buffer, $queue, new NoopInsightsApiSendGate()))->onKernelResponse(
            new ResponseEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST, new Response())
        );

        $this->assertSame([], $captured, 'Subscriber must not enqueue when outbound was inactive at event time, even if integration is published before flush');
    }
}
