<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests;

use Doctrine\DBAL\DriverManager;
use MauticPlugin\MauticInsightsBundle\Service\ChainPersistentEventBuffer;
use MauticPlugin\MauticInsightsBundle\Service\DatabaseEventBuffer;
use MauticPlugin\MauticInsightsBundle\Service\FileEventBuffer;
use MauticPlugin\MauticInsightsBundle\Service\RedisEventBuffer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DatabaseEventBufferTest extends TestCase
{
    public function testAppendLoadClearWithSqlite(): void
    {
        $conn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $logger = new NullLogger();
        $buffer = new DatabaseEventBuffer($conn, $logger, 'plugin_mautic_insights_api_events_test');

        $buffer->append([
            ['event' => 'mautic.test', 'ts' => '2026-01-01T00:00:00+00:00', 'count' => 2],
        ]);

        $result = $buffer->loadAndLock();
        $this->assertTrue($result->lockHeld);
        $this->assertCount(1, $result->events);
        $this->assertSame('mautic.test', $result->events[0]['event']);
        $this->assertSame(2, $result->events[0]['count']);

        $buffer->clear();

        $again = $buffer->loadAndLock();
        $this->assertFalse($again->lockHeld);
        $this->assertSame([], $again->events);
    }

    public function testChainSelectsDatabaseWhenFileAndRedisUnavailable(): void
    {
        $conn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $logger = new NullLogger();
        $invalidFile = sys_get_temp_dir().'/mautic-insights-no-dir-'.uniqid('', true).'/events.jsonl';
        $chain = new ChainPersistentEventBuffer(
            new FileEventBuffer($invalidFile, $logger),
            new RedisEventBuffer(null, $logger),
            new DatabaseEventBuffer($conn, $logger, 'plugin_mautic_insights_api_events_chain'),
        );

        $this->assertTrue($chain->isEnabled());
        $chain->append([['event' => 'e', 'ts' => '2026-01-01T00:00:00+00:00', 'count' => 1]]);
        $r = $chain->loadAndLock();
        $this->assertTrue($r->lockHeld);
        $chain->clear();
    }
}
