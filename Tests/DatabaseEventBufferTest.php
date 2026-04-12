<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests;

use Doctrine\DBAL\DriverManager;
use MauticPlugin\MauticInsightsBundle\Buffer\ChainPersistentEventBuffer;
use MauticPlugin\MauticInsightsBundle\Buffer\DatabaseEventBuffer;
use MauticPlugin\MauticInsightsBundle\Buffer\FileEventBuffer;
use MauticPlugin\MauticInsightsBundle\Buffer\RedisEventBuffer;
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

        // Use an existing *file* as a fake parent directory so that mkdir() cannot
        // create a subdirectory inside it → FileEventBuffer stays disabled.
        $fakeParent = tempnam(sys_get_temp_dir(), 'mi-chain-notadir-');
        $invalidFile = $fakeParent.'/subdir/events.jsonl';

        $chain = new ChainPersistentEventBuffer(
            new FileEventBuffer($invalidFile, $logger),
            new RedisEventBuffer(null, $logger),
            new DatabaseEventBuffer($conn, $logger, 'plugin_mautic_insights_api_events_chain'),
        );

        $this->assertSame('database', $chain->getActiveBackendId(), 'Chain must fall back to database when file dir cannot be created and Redis is unavailable.');
        $chain->append([['event' => 'e', 'ts' => '2026-01-01T00:00:00+00:00', 'count' => 1]]);
        $r = $chain->loadAndLock();
        $this->assertTrue($r->lockHeld);
        $chain->clear();

        @unlink($fakeParent);
    }
}
