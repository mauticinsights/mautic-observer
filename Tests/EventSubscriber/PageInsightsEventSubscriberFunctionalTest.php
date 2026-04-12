<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\EventSubscriber;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PageBundle\Entity\Hit;
use Mautic\PageBundle\Entity\Page;
use Mautic\PageBundle\Event\PageHitEvent;
use Mautic\PageBundle\PageEvents;
use MauticPlugin\MauticInsightsBundle\Buffer\DeferredOutboundEventQueue;
use MauticPlugin\MauticInsightsBundle\Buffer\MauticEventBuffer;
use MauticPlugin\MauticInsightsBundle\EventSubscriber\PageInsightsEventSubscriber;
use MauticPlugin\MauticInsightsBundle\Service\LeadInsightsContextBuilder;
use MauticPlugin\MauticInsightsBundle\Tests\Support\CapturingBufferTestSupport;
use PHPUnit\Framework\TestCase;

/**
 * Functional tests for {@see PageInsightsEventSubscriber}: PAGE_ON_HIT payload via deferred queue → POST /api/event.
 */
final class PageInsightsEventSubscriberFunctionalTest extends TestCase
{
    use CapturingBufferTestSupport;

    /**
     * @param array<array<string,int>> $listRows
     *
     * @return array{MauticEventBuffer, DeferredOutboundEventQueue, PageInsightsEventSubscriber}
     */
    private function makePageStack(array $listRows = []): array
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);
        $leadModel = new LeadModel();
        $leadModel->listRows = $listRows;
        $queue = new DeferredOutboundEventQueue();
        $subscriber = new PageInsightsEventSubscriber($queue, new LeadInsightsContextBuilder($leadModel));

        return [$buffer, $queue, $subscriber];
    }

    public function testRegistersExpectedEvents(): void
    {
        $map = PageInsightsEventSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey(PageEvents::PAGE_ON_HIT, $map);
        $this->assertSame(['onPageHit', 0], $map[PageEvents::PAGE_ON_HIT]);
    }

    public function testPageHitRequestBody(): void
    {
        [$buffer, $queue, $subscriber] = $this->makePageStack([['id' => 6]]);

        $lead = new Lead();
        $lead->stubId = 3;
        $lead->stubEmail = 'hit@example.com';
        $lead->stubFirstname = 'Harry';

        $hit = new Hit(new Page(12), $lead);
        $subscriber->onPageHit(new PageHitEvent($hit));

        $queue->flushTo($buffer);
        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertSame([
            [
                'event' => PageEvents::PAGE_ON_HIT,
                'properties' => [
                    'page_id' => 12,
                    'email' => 'hit@example.com',
                    'first_name' => 'Harry',
                ],
                'contact_id' => 3,
                'segment_ids' => [6],
            ],
        ], $rows);
    }

    public function testPageHitWithoutLeadOmitsContactFields(): void
    {
        [$buffer, $queue, $subscriber] = $this->makePageStack();

        $hit = new Hit(new Page(7), null);
        $subscriber->onPageHit(new PageHitEvent($hit));

        $queue->flushTo($buffer);
        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertSame([
            [
                'event' => PageEvents::PAGE_ON_HIT,
                'properties' => [
                    'page_id' => 7,
                ],
            ],
        ], $rows);
    }
}
