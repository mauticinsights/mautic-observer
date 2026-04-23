<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\EventSubscriber;

use DateTimeImmutable;
use DateTimeZone;
use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Entity\Submission;
use Mautic\FormBundle\Event\SubmissionEvent;
use Mautic\FormBundle\FormEvents;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PageBundle\Entity\Page;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticInsightsBundle\Buffer\DeferredOutboundEventQueue;
use MauticPlugin\MauticInsightsBundle\Buffer\DisabledPersistentEventBuffer;
use MauticPlugin\MauticInsightsBundle\Buffer\MauticEventBuffer;
use MauticPlugin\MauticInsightsBundle\EventSubscriber\FormInsightsEventSubscriber;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Service\FormSubmitPayloadBuilder;
use MauticPlugin\MauticInsightsBundle\Service\InsightsHttpTokenResolver;
use MauticPlugin\MauticInsightsBundle\Service\InsightsOutboundTokenProvider;
use MauticPlugin\MauticInsightsBundle\Service\InsightsPublishedIntegrationFinder;
use MauticPlugin\MauticInsightsBundle\Service\LeadInsightsContextBuilder;
use MauticPlugin\MauticInsightsBundle\Service\NoopInsightsApiSendGate;
use MauticPlugin\MauticInsightsBundle\Service\ResilientEventSender;
use MauticPlugin\MauticInsightsBundle\Tests\Support\CapturingBufferTestSupport;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * {@see FormInsightsEventSubscriber}: {@code mautic.form_on_submit} via deferred queue → POST /api/event.
 */
final class FormInsightsEventSubscriberTest extends TestCase
{
    use CapturingBufferTestSupport;

    /**
     * @param array<array<string,int>> $listRows
     *
     * @return array{MauticEventBuffer, DeferredOutboundEventQueue, FormInsightsEventSubscriber}
     */
    private function makeFormStack(array $listRows = []): array
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);
        $leadModel = new LeadModel();
        $leadModel->listRows = $listRows;
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new FormInsightsEventSubscriber(
            $queue,
            new FormSubmitPayloadBuilder(new LeadInsightsContextBuilder($leadModel)),
            $this->createActiveTestOutboundTokenProvider()
        );

        return [$buffer, $queue, $subscriber];
    }

    public function testRegistersFormOnSubmit(): void
    {
        $map = FormInsightsEventSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey(FormEvents::FORM_ON_SUBMIT, $map);
        $this->assertSame('onFormSubmit', $map[FormEvents::FORM_ON_SUBMIT][0]);
    }

    public function testFormSubmitPayloadIncludesMetadataAndLeadFields(): void
    {
        [$buffer, $queue, $subscriber] = $this->makeFormStack([['id' => 2]]);

        $lead = new Lead();
        $lead->stubId = 4;
        $lead->stubEmail = 'form@example.com';
        $lead->stubFirstname = 'Frank';

        $submittedAt = new DateTimeImmutable('2026-03-01T12:30:00+00:00');
        $submission = new Submission(
            new Form(55, 'Contact form', 'contact_form'),
            $lead,
            9001,
            ['email' => 'hidden@example.com', 'first_name' => 'hidden'],
            'trk-form-1',
            'https://landing.example/page?utm_source=news',
            $submittedAt,
            new Page(7, 'Landing page title'),
        );

        $subscriber->onFormSubmit(new SubmissionEvent($submission));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertSame(FormEvents::FORM_ON_SUBMIT, $rows[0]['event']);
        $this->assertSame('form', $rows[0]['channel']);
        $this->assertSame(4, $rows[0]['contact_id']);
        $this->assertSame([2], $rows[0]['segment_ids']);
        $this->assertSame('trk-form-1', $rows[0]['tracking_id']);
        $props = $rows[0]['properties'];
        $this->assertSame(55, $props['form_id']);
        $this->assertSame('Contact form', $props['form_name']);
        $this->assertSame('contact_form', $props['form_alias']);
        $this->assertSame(9001, $props['submission_id']);
        $this->assertSame(2, $props['field_count']);
        $this->assertSame(['email', 'first_name'], $props['field_aliases']);
        $this->assertSame(7, $props['page_id']);
        $this->assertSame('Landing page title', $props['page_title']);
        $this->assertSame('form@example.com', $props['email']);
        $this->assertSame('Frank', $props['first_name']);
        $this->assertStringStartsWith('https://landing.example/page', (string) $props['referer']);
        $this->assertSame($submittedAt->setTimezone(new DateTimeZone('UTC'))->format(\DATE_ATOM), $props['submitted_at']);
    }

    public function testFormSubmitWithoutLeadHasFormMetadataOnly(): void
    {
        [$buffer, $queue, $subscriber] = $this->makeFormStack();

        $submission = new Submission(
            new Form(33, 'Quick', 'quick'),
            null,
            42,
            ['a' => '1'],
            'anon-trk',
        );

        $subscriber->onFormSubmit(new SubmissionEvent($submission));
        $queue->flushTo($buffer);

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['contact_id'] ?? null);
        $this->assertSame('anon-trk', $rows[0]['tracking_id']);
        $props = $rows[0]['properties'];
        $this->assertSame(33, $props['form_id']);
        $this->assertSame(42, $props['submission_id']);
        $this->assertSame(1, $props['field_count']);
        $this->assertSame(['a'], $props['field_aliases']);
    }

    public function testWhenOutboundInactiveNothingIsEnqueuedOrSent(): void
    {
        $client = new MockHttpClient(static function (): void {
            throw new RuntimeException('no HTTP when outbound inactive');
        });
        $integration = new Integration(false, [], InsightsApiConfig::NAME, [InsightsApiConfig::HTTP_TOKEN_FIELD => 'x']);
        $tokenProvider = new InsightsOutboundTokenProvider(
            InsightsPublishedIntegrationFinder::createForTest($integration),
            new InsightsHttpTokenResolver(null),
            null
        );
        $buffer = new MauticEventBuffer(
            new ResilientEventSender($client, new NullLogger(), 1, 0),
            $tokenProvider,
            new DisabledPersistentEventBuffer(),
            new NoopInsightsApiSendGate(),
            5000
        );
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new FormInsightsEventSubscriber(
            $queue,
            new FormSubmitPayloadBuilder(new LeadInsightsContextBuilder(new LeadModel())),
            $tokenProvider
        );
        $submission = new Submission(new Form(1), null);
        $subscriber->onFormSubmit(new SubmissionEvent($submission));
        $queue->flushTo($buffer);
        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertSame([], $rows);
    }
}
