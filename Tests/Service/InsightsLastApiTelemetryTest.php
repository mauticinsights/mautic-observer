<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\Service;

use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Service\InsightsLastApiTelemetry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class InsightsLastApiTelemetryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/mautic-insights-telemetry-test-'.uniqid('', true);
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $p = $this->dir.'/mautic_insights_last_api.json';
        if (is_file($p)) {
            @unlink($p);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testLegacySingleFileMapsToEventBucketByUrl(): void
    {
        file_put_contents(
            $this->dir.'/mautic_insights_last_api.json',
            json_encode([
                'ok' => true,
                'at' => '2020-01-01T00:00:00+00:00',
                'url' => InsightsApiConfig::EVENT_NOTIFY_URL,
                'method' => 'POST',
                'http_status' => 204,
                'error' => null,
            ], JSON_THROW_ON_ERROR)
        );

        $t = new InsightsLastApiTelemetry($this->dir, new NullLogger());
        $this->assertNotNull($t->getLastEvent());
        $this->assertTrue($t->getLastEvent()['ok']);
        $this->assertNull($t->getLastStatus());
    }

    public function testEventAndStatusStoredIndependently(): void
    {
        $t = new InsightsLastApiTelemetry($this->dir, new NullLogger());

        $t->record(InsightsApiConfig::EVENT_NOTIFY_URL, 'POST', 200, true);
        $t->record(InsightsApiConfig::STATUS_NOTIFY_URL, 'POST', 500, false, 'err');

        $e = $t->getLastEvent();
        $s = $t->getLastStatus();
        $this->assertNotNull($e);
        $this->assertTrue($e['ok']);
        $this->assertSame(200, $e['http_status']);

        $this->assertNotNull($s);
        $this->assertFalse($s['ok']);
        $this->assertSame(500, $s['http_status']);
        $this->assertSame('err', $s['error']);
    }
}
