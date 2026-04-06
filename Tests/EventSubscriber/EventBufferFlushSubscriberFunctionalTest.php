<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\EventSubscriber;

use MauticPlugin\MauticInsightsBundle\EventSubscriber\EventBufferFlushSubscriber;
use MauticPlugin\MauticInsightsBundle\Service\DeferredOutboundEventQueue;
use MauticPlugin\MauticInsightsBundle\Tests\Support\CapturingBufferTestSupport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class EventBufferFlushSubscriberFunctionalTest extends TestCase
{
    use CapturingBufferTestSupport;

    public function testRegistersExpectedEvents(): void
    {
        $map = EventBufferFlushSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey(KernelEvents::RESPONSE, $map);
        $this->assertArrayHasKey(KernelEvents::TERMINATE, $map);
    }

    public function testFlushesBufferToEventApi(): void
    {
        $client = $this->createCapturingEventHttpClient();
        $buffer = $this->createMauticEventBufferForTests($client);

        $expectedRow = [
            'event' => 'mautic.custom.probe',
            'channel' => 'test',
        ];

        $flushSubscriber = new EventBufferFlushSubscriber($buffer, new DeferredOutboundEventQueue());
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/');

        $buffer->push('mautic.custom.probe', ['channel' => 'test']);

        $flushSubscriber->onKernelResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response()));
        $flushSubscriber->onKernelTerminate(new TerminateEvent($kernel, $request, new Response()));

        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);
        $this->assertSame([$expectedRow], $rows);
    }
}
