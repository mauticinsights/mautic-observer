<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests;

use MauticPlugin\MauticInsightsBundle\Service\StatusNotifyPayload;
use PHPUnit\Framework\TestCase;

final class StatusNotifyPayloadTest extends TestCase
{
    public function testBuildClampsContactCountsAndIncludesSenderEmail(): void
    {
        $payload = StatusNotifyPayload::build('5.0.0', '2.1.0', -1, -3, 'from@example.org');

        $this->assertSame([
            'mautic-version' => '5.0.0',
            'plugin-version' => '2.1.0',
            'identified-contacts' => 0,
            'anonymous-contacts' => 0,
            'sender-email' => 'from@example.org',
        ], $payload);
    }
}
