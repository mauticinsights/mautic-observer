<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\EventSubscriber;

use DateTimeImmutable;
use Mautic\EmailBundle\Entity\Email;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadDevice;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PageBundle\Entity\Hit;
use Mautic\PageBundle\Entity\Page;
use Mautic\PageBundle\Entity\Redirect;
use Mautic\PageBundle\Event\PageHitEvent;
use Mautic\PageBundle\PageEvents;
use MauticPlugin\MauticInsightsBundle\Buffer\DeferredOutboundEventQueue;
use MauticPlugin\MauticInsightsBundle\Buffer\MauticEventBuffer;
use MauticPlugin\MauticInsightsBundle\EventSubscriber\PageInsightsEventSubscriber;
use MauticPlugin\MauticInsightsBundle\Service\LeadInsightsContextBuilder;
use MauticPlugin\MauticInsightsBundle\Service\PageHitPayloadBuilder;
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
        $subscriber = new PageInsightsEventSubscriber(
            $queue,
            new PageHitPayloadBuilder(new LeadInsightsContextBuilder($leadModel)),
        );

        return [$buffer, $queue, $subscriber];
    }

    public function testRegistersExpectedEvents(): void
    {
        $map = PageInsightsEventSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey(PageEvents::PAGE_ON_HIT, $map);
        $this->assertSame(['onPageHit', 0], $map[PageEvents::PAGE_ON_HIT]);
    }

    public function testPageHitMinimalLandingPage(): void
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
                'channel' => 'page',
                'properties' => [
                    'email' => 'hit@example.com',
                    'first_name' => 'Harry',
                    'is_unique' => false,
                    'hit_type' => 'landing',
                    'page_id' => 12,
                ],
                'contact_id' => 3,
                'segment_ids' => [6],
            ],
        ], $rows);
    }

    public function testPageHitExternalWithoutLeadOmitsContactFields(): void
    {
        [$buffer, $queue, $subscriber] = $this->makePageStack();

        $hit = new Hit(null, null);
        $subscriber->onPageHit(new PageHitEvent($hit));

        $queue->flushTo($buffer);
        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertSame([
            [
                'event' => PageEvents::PAGE_ON_HIT,
                'channel' => 'page',
                'properties' => [
                    'is_unique' => false,
                    'hit_type' => 'external',
                ],
            ],
        ], $rows);
    }

    public function testPageHitRichPayloadWithEmailClickthrough(): void
    {
        [$buffer, $queue, $subscriber] = $this->makePageStack([['id' => 6]]);

        $lead = new Lead();
        $lead->stubId = 3;
        $lead->stubEmail = 'hit@example.com';

        $device = new LeadDevice();
        $device->stubDevice = 'desktop';
        $device->stubDeviceOsName = 'macOS';
        $device->stubDeviceOsVersion = '14.2';
        $device->stubDeviceOsPlatform = 'x64';
        $device->stubClientInfo = ['name' => 'Chrome', 'version' => '124', 'engine' => 'Blink'];

        $hit = new Hit(null, $lead);
        $hit->stubId = 987654;
        $hit->stubCode = 200;
        $hit->stubTrackingId = 'track-abc';
        $hit->stubDateHit = new DateTimeImmutable('2026-04-18T10:15:23.123456+02:00');
        $hit->stubUrl = 'https://acme.com/pricing?utm_source=newsletter&utm_medium=email&utm_campaign=spring24&foo=bar';
        $hit->stubUrlTitle = 'Pricing — Acme';
        $hit->stubReferer = 'https://www.google.com/';
        $hit->stubPageLanguage = 'en';
        $hit->stubCountry = 'CZ';
        $hit->stubRegion = 'Prague';
        $hit->stubCity = 'Praha';
        $hit->stubIsp = 'O2';
        $hit->stubOrganization = 'O2 Czech Republic';
        $hit->stubSource = 'email';
        $hit->stubSourceId = 42;
        $hit->stubQuery = [
            'utm_source' => 'newsletter',
            'utm_medium' => 'email',
            'utm_campaign' => 'spring24',
            'page_referrer' => 'https://www.google.com/',
            'page_language' => 'en',
            'timezone_offset' => '-60',
            'ct' => ['internal-token-should-be-dropped'],
            'foo' => 'bar',
            'plan' => 'pro',
        ];
        $hit->stubBrowserLanguages = ['cs-CZ', 'en-US'];
        $hit->stubEmail = new Email(42, 'Spring');
        $hit->stubDevice = $device;

        $event = new PageHitEvent(
            $hit,
            code: 200,
            clickthroughData: [
                'channel' => ['campaign' => 7],
                'source' => ['email', 42],
                'lead' => 99,
            ],
            unique: true,
        );
        $subscriber->onPageHit($event);

        $queue->flushTo($buffer);

        $buffer->flush();
        $this->assertCount(1, $this->capturedEventApiRequests);
        $body = json_decode($this->capturedEventApiRequests[0]['options']['body'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($body['events'] ?? null);
        $this->assertCount(1, $body['events']);
        $rawRow = $body['events'][0];
        $this->assertArrayHasKey('ts', $rawRow);
        $rowTs = $rawRow['ts'];
        $row = $rawRow;
        unset($row['ts']);

        $this->assertSame(PageEvents::PAGE_ON_HIT, $row['event']);
        $this->assertSame('email', $row['channel']);
        $this->assertSame(3, $row['contact_id']);
        $this->assertSame([6], $row['segment_ids']);
        $this->assertSame('track-abc', $row['tracking_id']);
        $this->assertSame(42, $row['email_id']);
        $this->assertSame(7, $row['campaign_id']);

        $props = $row['properties'];
        $this->assertSame(true, $props['is_unique']);
        $this->assertSame(200, $props['http_code']);
        $this->assertSame(987654, $props['hit_id']);
        $this->assertSame('external', $props['hit_type']);
        $this->assertArrayHasKey('date_hit', $props);
        $this->assertMatchesRegularExpression('/^2026-04-18T08:15:23\.123456Z$/', $props['date_hit']);
        $this->assertStringStartsWith('https://acme.com/pricing', $props['url']);
        $this->assertSame('Pricing — Acme', $props['url_title']);
        $this->assertSame('en', $props['page_language']);
        $this->assertSame('https://www.google.com/', $props['referer']);
        $this->assertSame(
            ['source' => 'newsletter', 'medium' => 'email', 'campaign' => 'spring24'],
            $props['utm'],
        );
        $this->assertSame('email', $props['source']);
        $this->assertSame(42, $props['source_id']);
        $this->assertSame(
            ['country' => 'CZ', 'region' => 'Prague', 'city' => 'Praha'],
            $props['geo'],
        );
        $this->assertSame(
            ['isp' => 'O2', 'organization' => 'O2 Czech Republic'],
            $props['network'],
        );
        $this->assertSame(
            [
                'type' => 'desktop',
                'os' => ['name' => 'macOS', 'version' => '14.2', 'platform' => 'x64'],
                'browser' => ['name' => 'Chrome', 'version' => '124', 'engine' => 'Blink'],
            ],
            $props['device'],
        );
        $this->assertSame(['cs-CZ', 'en-US'], $props['languages']);
        $this->assertSame(-60, $props['timezone_offset']);
        $this->assertSame(['foo' => 'bar', 'plan' => 'pro'], $props['custom']);
        $this->assertArrayHasKey('clickthrough', $props);
        $this->assertSame(['channel' => ['campaign' => 7], 'source' => ['email', 42]], $props['clickthrough']);

        $this->assertSame($props['date_hit'], $rowTs);
    }

    public function testPageHitRedirectTypeIsDetected(): void
    {
        [$buffer, $queue, $subscriber] = $this->makePageStack();

        $hit = new Hit(null, null);
        $hit->stubRedirect = new Redirect(55);

        $subscriber->onPageHit(new PageHitEvent($hit));
        $queue->flushTo($buffer);
        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertSame('redirect', $rows[0]['properties']['hit_type']);
        $this->assertSame(55, $rows[0]['properties']['redirect_id']);
    }
}
