<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\EventSubscriber;

use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\EmailBundle\Event\EmailEvent;
use Mautic\EmailBundle\Event\EmailOpenEvent;
use Mautic\EmailBundle\Event\EmailSendEvent;
use Mautic\EmailBundle\Event\QueueEmailEvent;
use Mautic\EmailBundle\Event\TransportWebhookEvent;
use Mautic\EmailBundle\Mailer\Message\MauticMessage;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PluginBundle\Entity\Integration;
use Mautic\PointBundle\Entity\TriggerEvent as PointTriggerEvent;
use Mautic\PointBundle\Event\TriggerExecutedEvent;
use MauticPlugin\MauticInsightsBundle\Buffer\DeferredOutboundEventQueue;
use MauticPlugin\MauticInsightsBundle\Buffer\DisabledPersistentEventBuffer;
use MauticPlugin\MauticInsightsBundle\Buffer\MauticEventBuffer;
use MauticPlugin\MauticInsightsBundle\EventSubscriber\EmailInsightsEventSubscriber;
use MauticPlugin\MauticInsightsBundle\EventSubscriber\EventBufferFlushSubscriber;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Service\EmailInsightsContextBuilder;
use MauticPlugin\MauticInsightsBundle\Service\InsightsHttpTokenResolver;
use MauticPlugin\MauticInsightsBundle\Service\InsightsOutboundTokenProvider;
use MauticPlugin\MauticInsightsBundle\Service\InsightsPublishedIntegrationFinder;
use MauticPlugin\MauticInsightsBundle\Service\LeadInsightsContextBuilder;
use MauticPlugin\MauticInsightsBundle\Service\NoopInsightsApiSendGate;
use MauticPlugin\MauticInsightsBundle\Service\ResilientEventSender;
use MauticPlugin\MauticInsightsBundle\Tests\Support\CapturingBufferTestSupport;
use MauticPlugin\MauticInsightsBundle\Tests\Support\MauticLeadTestFixtures;
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
 * Functional tests for {@see EmailInsightsEventSubscriber}: payload shape and batched POST /api/event (buffer off).
 */
final class EmailInsightsEventSubscriberFunctionalTest extends TestCase
{
    use CapturingBufferTestSupport;
    use MauticLeadTestFixtures;

    public function testRegistersExpectedEvents(): void
    {
        $map = EmailInsightsEventSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey(EmailEvents::EMAIL_ON_OPEN, $map);
        $this->assertArrayHasKey(EmailEvents::EMAIL_ON_SEND, $map);
        $this->assertArrayHasKey(EmailEvents::EMAIL_ON_DISPLAY, $map);
        $this->assertArrayHasKey(EmailEvents::EMAIL_POST_SAVE, $map);
        $this->assertArrayHasKey(EmailEvents::EMAIL_POST_DELETE, $map);
        $this->assertArrayHasKey(EmailEvents::EMAIL_ON_REPLY, $map);
        $this->assertArrayHasKey(EmailEvents::EMAIL_FAILED, $map);
        $this->assertArrayHasKey(EmailEvents::EMAIL_RESEND, $map);
        $this->assertArrayHasKey(EmailEvents::ON_SENT_EMAIL_TO_USER, $map);
        $this->assertArrayHasKey(EmailEvents::ON_TRANSPORT_WEBHOOK, $map);
    }

    public function testEmailPostSaveCreatedAndUpdatedPayloadShapes(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);

        $leadModel = new LeadModel();
        $emailBuilder = new EmailInsightsContextBuilder(new LeadInsightsContextBuilder($leadModel));
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new EmailInsightsEventSubscriber($queue, $emailBuilder, $this->createActiveTestOutboundTokenProvider());

        $created = new Email(1, 'Welcome', 'Hello', 'list', false);
        $subscriber->onEmailPostSave(new EmailEvent($created, true));

        $updated = new Email(1, 'Welcome v2', 'Hello again', 'template', true);
        $subscriber->onEmailPostSave(new EmailEvent($updated, false));

        $queue->flushTo($buffer);
        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertSame([
            [
                'event' => EmailEvents::EMAIL_POST_SAVE,
                'email_id' => 1,
                'properties' => [
                    'name' => 'Welcome',
                    'operation' => 'created',
                    'subject' => 'Hello',
                    'email_type' => 'list',
                    'is_published' => false,
                ],
            ],
            [
                'event' => EmailEvents::EMAIL_POST_SAVE,
                'email_id' => 1,
                'properties' => [
                    'name' => 'Welcome v2',
                    'operation' => 'updated',
                    'subject' => 'Hello again',
                    'email_type' => 'template',
                    'is_published' => true,
                ],
            ],
        ], $rows);
    }

    public function testEmailPostSaveOmitsEmptySubject(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);

        $leadModel = new LeadModel();
        $emailBuilder = new EmailInsightsContextBuilder(new LeadInsightsContextBuilder($leadModel));
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new EmailInsightsEventSubscriber($queue, $emailBuilder, $this->createActiveTestOutboundTokenProvider());

        $email = new Email(3, 'No subject yet', '', 'template', true);
        $subscriber->onEmailPostSave(new EmailEvent($email, true));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertSame([
            [
                'event' => EmailEvents::EMAIL_POST_SAVE,
                'email_id' => 3,
                'properties' => [
                    'name' => 'No subject yet',
                    'operation' => 'created',
                    'email_type' => 'template',
                    'is_published' => true,
                ],
            ],
        ], $rows);
    }

    public function testEmailPostSaveSetsSegmentIdFromFirstAssociatedList(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);

        $leadModel = new LeadModel();
        $emailBuilder = new EmailInsightsContextBuilder(new LeadInsightsContextBuilder($leadModel));
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new EmailInsightsEventSubscriber($queue, $emailBuilder, $this->createActiveTestOutboundTokenProvider());

        $email = new Email(10, 'List mail', 'Hi', 'list', true);
        $email->lists = [
            new LeadList(5, 'A', 'a', null),
            new LeadList(6, 'B', 'b', null),
        ];
        $subscriber->onEmailPostSave(new EmailEvent($email, true));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertSame(5, $rows[0]['properties']['segment_id']);
    }

    public function testEmailOpenRequestBodies(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);

        $leadModel = new LeadModel();
        $leadModel->listRows = [['id' => 1], ['id' => 2]];

        $emailBuilder = new EmailInsightsContextBuilder(new LeadInsightsContextBuilder($leadModel));
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new EmailInsightsEventSubscriber($queue, $emailBuilder, $this->createActiveTestOutboundTokenProvider());

        $lead = $this->makeLead(42, 'open@example.com');
        $list = new LeadList(99);
        $stat = new Stat(
            new Email(7, 'Newsletter', 'Weekly'),
            $lead,
            'campaign',
            50,
            $list,
        );

        $subscriber->onEmailOpen(new EmailOpenEvent($stat, true));
        $subscriber->onEmailOpen(new EmailOpenEvent($stat, false));
        $queue->flushTo($buffer);

        $expectedFirst = [
            'event' => EmailEvents::EMAIL_ON_OPEN,
            'channel' => 'email',
            'email_id' => 7,
            'properties' => [
                'email_name' => 'Newsletter',
                'subject' => 'Weekly',
                'email' => 'open@example.com',
                'first_open' => true,
            ],
            'campaign_id' => 50,
            'contact_id' => 42,
            'segment_ids' => [1, 2, 99],
        ];

        $expectedSecond = [
            'event' => EmailEvents::EMAIL_ON_OPEN,
            'channel' => 'email',
            'email_id' => 7,
            'properties' => [
                'email_name' => 'Newsletter',
                'subject' => 'Weekly',
                'email' => 'open@example.com',
                'first_open' => false,
            ],
            'campaign_id' => 50,
            'contact_id' => 42,
            'segment_ids' => [1, 2, 99],
        ];

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertSame([$expectedFirst, $expectedSecond], $rows);
    }

    public function testEmailSendSkipsInternalOrMissingLeadAndBuildsRows(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);

        $leadModel = new LeadModel();
        $leadModel->listRows = [['id' => 5]];

        $emailBuilder = new EmailInsightsContextBuilder(new LeadInsightsContextBuilder($leadModel));
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new EmailInsightsEventSubscriber($queue, $emailBuilder, $this->createActiveTestOutboundTokenProvider());

        $internal = new EmailSendEvent();
        $internal->internalSend = true;
        $internal->lead = $this->makeLead(1);

        $missingLead = new EmailSendEvent();
        $missingLead->internalSend = false;
        $missingLead->lead = null;

        $full = new EmailSendEvent();
        $full->internalSend = false;
        $full->lead = $this->makeLead(42, 'send@example.com');
        $full->email = new Email(7, 'Onboarding #3', 'Hello');
        $full->source = ['campaign', 100];

        $arrayLeadOnly = new EmailSendEvent();
        $arrayLeadOnly->internalSend = false;
        $arrayLeadOnly->lead = ['id' => 200];
        $arrayLeadOnly->email = new Email(8, 'Array lead', 'Subj');

        $subscriber->onEmailSend($internal);
        $subscriber->onEmailSend($missingLead);
        $subscriber->onEmailSend($full);
        $subscriber->onEmailSend($arrayLeadOnly);
        $queue->flushTo($buffer);

        $expectedFullRow = [
            'event' => EmailEvents::EMAIL_ON_SEND,
            'channel' => 'email',
            'email_id' => 7,
            'properties' => [
                'email_name' => 'Onboarding #3',
                'subject' => 'Hello',
                'email' => 'send@example.com',
            ],
            'campaign_id' => 100,
            'contact_id' => 42,
            'segment_ids' => [5],
        ];

        $expectedArrayLeadRow = [
            'event' => EmailEvents::EMAIL_ON_SEND,
            'channel' => 'email',
            'email_id' => 8,
            'properties' => [
                'email_name' => 'Array lead',
                'subject' => 'Subj',
            ],
            'contact_id' => 200,
        ];

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertSame([$expectedFullRow, $expectedArrayLeadRow], $rows);
    }

    public function testEmailDisplayDeferredUsesSamePayloadShapeAsSendWithDifferentEventName(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);

        $leadModel = new LeadModel();
        $leadModel->listRows = [['id' => 5]];

        $emailBuilder = new EmailInsightsContextBuilder(new LeadInsightsContextBuilder($leadModel));
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new EmailInsightsEventSubscriber($queue, $emailBuilder, $this->createActiveTestOutboundTokenProvider());

        $display = new EmailSendEvent();
        $display->internalSend = false;
        $display->lead = $this->makeLead(42, 'display@example.com');
        $display->email = new Email(7, 'Browser', 'Hello');
        $display->source = ['campaign', 100];

        $subscriber->onEmailDisplay($display);
        $queue->flushTo($buffer);

        $expectedRow = [
            'event' => EmailEvents::EMAIL_ON_DISPLAY,
            'channel' => 'email',
            'email_id' => 7,
            'properties' => [
                'email_name' => 'Browser',
                'subject' => 'Hello',
                'email' => 'display@example.com',
            ],
            'campaign_id' => 100,
            'contact_id' => 42,
            'segment_ids' => [5],
        ];

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertSame([$expectedRow], $rows);
    }

    public function testEmailPostDeletePayload(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);

        $leadModel = new LeadModel();
        $emailBuilder = new EmailInsightsContextBuilder(new LeadInsightsContextBuilder($leadModel));
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new EmailInsightsEventSubscriber($queue, $emailBuilder, $this->createActiveTestOutboundTokenProvider());

        $subscriber->onEmailPostDelete(new EmailEvent(new Email(10, 'Old template', 'Subj'), false));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertSame([
            [
                'event' => EmailEvents::EMAIL_POST_DELETE,
                'email_id' => 10,
                'properties' => [
                    'name' => 'Old template',
                    'operation' => 'deleted',
                ],
            ],
        ], $rows);
    }

    /**
     * Same as Mautic {@see \Mautic\CoreBundle\Model\FormModel::deleteEntity()}: {@code getId()} is empty, {@code deletedId} holds the id.
     */
    public function testEmailPostDeleteUsesDeletedIdWhenGetIdIsNull(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);

        $leadModel = new LeadModel();
        $emailBuilder = new EmailInsightsContextBuilder(new LeadInsightsContextBuilder($leadModel));
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new EmailInsightsEventSubscriber($queue, $emailBuilder, $this->createActiveTestOutboundTokenProvider());

        $email = new Email(null, 'Gone', 'x');
        $email->deletedId = 42;

        $subscriber->onEmailPostDelete(new EmailEvent($email, false));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertCount(1, $rows);
        $this->assertSame(42, $rows[0]['email_id']);
        $this->assertSame(EmailEvents::EMAIL_POST_DELETE, $rows[0]['event']);
    }

    /**
     * Durable buffer off: rows are not sent on {@code enqueue}; after {@see EventBufferFlushSubscriber} runs, one POST
     * {@code { "events": [ open, send ] }} with full bodies (see assertions below).
     */
    public function testOpenAndSendBatchedInSinglePostWhenBufferDisabled(): void
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
        $leadModel->listRows = [['id' => 10]];
        $queue = new DeferredOutboundEventQueue();
        $buffer = new MauticEventBuffer($sender, $tokenProvider, new DisabledPersistentEventBuffer(), new NoopInsightsApiSendGate(), 5000);

        $emailBuilder = new EmailInsightsContextBuilder(new LeadInsightsContextBuilder($leadModel));
        $subscriber = new EmailInsightsEventSubscriber($queue, $emailBuilder, $tokenProvider);

        $lead = $this->makeLead(77, 'batch@example.com');
        $stat = new Stat(
            new Email(3, 'OpenTpl', 'Open subj'),
            $lead,
            'campaign',
            900,
            new LeadList(55),
        );
        $subscriber->onEmailOpen(new EmailOpenEvent($stat, true));

        $send = new EmailSendEvent();
        $send->internalSend = false;
        $send->lead = $lead;
        $send->email = new Email(4, 'SendTpl', 'Send subj');
        $send->source = ['campaign', 800];
        $subscriber->onEmailSend($send);

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/');
        $response = new Response();
        $flush = new EventBufferFlushSubscriber($buffer, $queue, new NoopInsightsApiSendGate());
        $flush->onKernelResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
        $flush->onKernelTerminate(new TerminateEvent($kernel, $request, $response));

        // One batched POST: durable buffer off still accumulates in memory until flush(); deferred rows are not sent per enqueue.
        $this->assertCount(1, $captured);
        $this->assertSame('POST', $captured[0]['method']);
        $this->assertSame(InsightsApiConfig::EVENT_NOTIFY_URL, $captured[0]['url']);

        $events = $captured[0]['decodedBody']['events'];
        $this->assertCount(2, $events);

        $openRow = $events[0];
        unset($openRow['ts']);
        $this->assertSame([
            'event' => EmailEvents::EMAIL_ON_OPEN,
            'channel' => 'email',
            'email_id' => 3,
            'properties' => [
                'email_name' => 'OpenTpl',
                'subject' => 'Open subj',
                'email' => 'batch@example.com',
                'first_open' => true,
            ],
            'campaign_id' => 900,
            'contact_id' => 77,
            'segment_ids' => [10, 55],
        ], $openRow);

        $sendRow = $events[1];
        unset($sendRow['ts']);
        $this->assertSame([
            'event' => EmailEvents::EMAIL_ON_SEND,
            'channel' => 'email',
            'email_id' => 4,
            'properties' => [
                'email_name' => 'SendTpl',
                'subject' => 'Send subj',
                'email' => 'batch@example.com',
            ],
            'campaign_id' => 800,
            'contact_id' => 77,
            'segment_ids' => [10],
        ], $sendRow);
    }

    public function testEmailOnReplySameShapeAsOpen(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);

        $leadModel = new LeadModel();
        $leadModel->listRows = [['id' => 1]];
        $emailBuilder = new EmailInsightsContextBuilder(new LeadInsightsContextBuilder($leadModel));
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new EmailInsightsEventSubscriber($queue, $emailBuilder, $this->createActiveTestOutboundTokenProvider());

        $lead = $this->makeLead(5, 'r@example.com');
        $stat = new Stat(
            new Email(9, 'T', 'S'),
            $lead,
            'campaign',
            3,
            new LeadList(1),
        );
        $subscriber->onEmailReply(new EmailOpenEvent($stat, false));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertSame(EmailEvents::EMAIL_ON_REPLY, $rows[0]['event']);
        $this->assertSame(9, $rows[0]['email_id']);
        $this->assertSame(5, $rows[0]['contact_id']);
    }

    public function testEmailFailedAndResendQueuePayload(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);

        $leadModel = new LeadModel();
        $emailBuilder = new EmailInsightsContextBuilder(new LeadInsightsContextBuilder($leadModel));
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new EmailInsightsEventSubscriber($queue, $emailBuilder, $this->createActiveTestOutboundTokenProvider());

        $from = new class {
            public function getAddress(): string
            {
                return 'from@x.test';
            }
        };
        $to = new class {
            public function getAddress(): string
            {
                return 'to@x.test';
            }
        };

        $msg = new MauticMessage('Subj line', 'abc-hash', [$from], [$to]);
        $subscriber->onEmailFailed(new QueueEmailEvent($msg));
        $subscriber->onEmailResend(new QueueEmailEvent($msg));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $expectedProps = [
            'lead_id_hash' => 'abc-hash',
            'subject' => 'Subj line',
            'from' => ['from@x.test'],
            'to' => ['to@x.test'],
        ];
        $this->assertSame([
            [
                'event' => EmailEvents::EMAIL_FAILED,
                'channel' => 'email',
                'properties' => $expectedProps,
            ],
            [
                'event' => EmailEvents::EMAIL_RESEND,
                'channel' => 'email',
                'properties' => $expectedProps,
            ],
        ], $rows);
    }

    public function testOnSentEmailToUserPayload(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);

        $leadModel = new LeadModel();
        $leadModel->listRows = [];
        $emailBuilder = new EmailInsightsContextBuilder(new LeadInsightsContextBuilder($leadModel));
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new EmailInsightsEventSubscriber($queue, $emailBuilder, $this->createActiveTestOutboundTokenProvider());

        $lead = $this->makeLead(88, 'user@example.com');
        $trigger = new PointTriggerEvent(12, 'Notify user', 'email.send');
        $subscriber->onSentEmailToUser(new TriggerExecutedEvent($trigger, $lead));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertCount(1, $rows);
        $this->assertSame(EmailEvents::ON_SENT_EMAIL_TO_USER, $rows[0]['event']);
        $this->assertSame(88, $rows[0]['contact_id']);
        $this->assertSame([
            'point_trigger_event_id' => 12,
            'point_trigger_event_name' => 'Notify user',
            'point_trigger_event_type' => 'email.send',
            'email' => 'user@example.com',
        ], $rows[0]['properties']);
    }

    public function testOnSentEmailToUserSkippedWhenLeadHasNoId(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);

        $leadModel = new LeadModel();
        $emailBuilder = new EmailInsightsContextBuilder(new LeadInsightsContextBuilder($leadModel));
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new EmailInsightsEventSubscriber($queue, $emailBuilder, $this->createActiveTestOutboundTokenProvider());

        $lead = new Lead();
        $lead->stubId = null;
        $subscriber->onSentEmailToUser(new TriggerExecutedEvent(new PointTriggerEvent(1, 'x', 't'), $lead));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertSame([], $rows);
    }

    public function testTransportWebhookPayload(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);

        $leadModel = new LeadModel();
        $emailBuilder = new EmailInsightsContextBuilder(new LeadInsightsContextBuilder($leadModel));
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new EmailInsightsEventSubscriber($queue, $emailBuilder, $this->createActiveTestOutboundTokenProvider());

        $req = Request::create('/webhooks/mailgun', 'POST', ['foo' => 'bar']);
        $subscriber->onTransportWebhook(new TransportWebhookEvent($req));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertSame([
            [
                'event' => EmailEvents::ON_TRANSPORT_WEBHOOK,
                'channel' => 'email',
                'properties' => [
                    'http_method' => 'POST',
                    'path' => '/webhooks/mailgun',
                ],
            ],
        ], $rows);
    }
}
