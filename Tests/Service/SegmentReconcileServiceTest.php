<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\Service;

use Doctrine\DBAL\Connection;
use MauticPlugin\MauticInsightsBundle\Service\SegmentCheckpointStore;
use MauticPlugin\MauticInsightsBundle\Service\SegmentReconcileService;
use MauticPlugin\MauticInsightsBundle\Tests\Support\CapturingBufferTestSupport;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SegmentReconcileServiceTest extends TestCase
{
    use CapturingBufferTestSupport;

    /**
     * Reconcile with an empty checkpoint (first run): all current memberships
     * should be emitted as "added".
     */
    public function testFirstRunEmitsAllCurrentAsAdded(): void
    {
        $connection = $this->createMockConnection([
            ['lead_id' => 1, 'leadlist_id' => 10],
            ['lead_id' => 1, 'leadlist_id' => 20],
            ['lead_id' => 2, 'leadlist_id' => 10],
        ]);

        $checkpoint = $this->createEmptyCheckpointStore();
        $buffer = $this->createMauticEventBufferForTests($this->createCapturingEventHttpClient());

        $service = new SegmentReconcileService($connection, $buffer, $checkpoint, new NullLogger());
        $stats = $service->reconcile();

        $this->assertSame(['added' => 3, 'removed' => 0, 'total_contacts' => 2], $stats);

        $buffer->flush();
        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertCount(3, $rows);
        $this->assertSame('mautic.lead_list_change', $rows[0]['event']);
        $this->assertSame(1, $rows[0]['contact_id']);
        $this->assertSame(10, $rows[0]['list_id']);
        $this->assertTrue($rows[0]['list_added']);
        // Full segment_ids: contact 1 has segments [10, 20]
        $this->assertSame([10, 20], $rows[0]['segment_ids']);

        $this->assertSame(1, $rows[1]['contact_id']);
        $this->assertSame(20, $rows[1]['list_id']);
        $this->assertTrue($rows[1]['list_added']);
        $this->assertSame([10, 20], $rows[1]['segment_ids']);

        $this->assertSame(2, $rows[2]['contact_id']);
        $this->assertSame(10, $rows[2]['list_id']);
        $this->assertTrue($rows[2]['list_added']);
        $this->assertSame([10], $rows[2]['segment_ids']);
    }

    /**
     * Second run with the same data: no changes, no events emitted.
     */
    public function testNoChangesEmitsNothing(): void
    {
        $connection = $this->createMockConnection([
            ['lead_id' => 1, 'leadlist_id' => 10],
        ]);

        $checkpoint = $this->createCheckpointStore([
            1 => [10],
        ]);
        $buffer = $this->createMauticEventBufferForTests($this->createCapturingEventHttpClient());

        $service = new SegmentReconcileService($connection, $buffer, $checkpoint, new NullLogger());
        $service->reconcile();

        $buffer->flush();
        $this->assertCount(0, $this->capturedEventApiRequests);
    }

    /**
     * Contact removed from all segments: emit "removed" for each previously-held segment.
     */
    public function testRemovedMembershipEmitsRemove(): void
    {
        $connection = $this->createMockConnection([
            // contact 1 is no longer in any segment
        ]);

        $checkpoint = $this->createCheckpointStore([
            1 => [10, 20],
        ]);
        $buffer = $this->createMauticEventBufferForTests($this->createCapturingEventHttpClient());

        $service = new SegmentReconcileService($connection, $buffer, $checkpoint, new NullLogger());
        $stats = $service->reconcile();

        $this->assertSame(['added' => 0, 'removed' => 2, 'total_contacts' => 0], $stats);

        $buffer->flush();
        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows[0]['contact_id']);
        $this->assertSame(10, $rows[0]['list_id']);
        $this->assertFalse($rows[0]['list_added']);
        // Contact 1 has no segments in DB now → segment_ids is empty
        $this->assertSame([], $rows[0]['segment_ids']);

        $this->assertSame(1, $rows[1]['contact_id']);
        $this->assertSame(20, $rows[1]['list_id']);
        $this->assertFalse($rows[1]['list_added']);
        $this->assertSame([], $rows[1]['segment_ids']);
    }

    /**
     * Mix of added and removed across contacts.
     */
    public function testMixedChanges(): void
    {
        $connection = $this->createMockConnection([
            ['lead_id' => 1, 'leadlist_id' => 10], // 1: had [10,20], now [10] → removed 20
            ['lead_id' => 2, 'leadlist_id' => 30], // 2: had [10], now [30] → removed 10, added 30
            ['lead_id' => 3, 'leadlist_id' => 10], // 3: new contact → added 10
        ]);

        $checkpoint = $this->createCheckpointStore([
            1 => [10, 20],
            2 => [10],
        ]);
        $buffer = $this->createMauticEventBufferForTests($this->createCapturingEventHttpClient());

        $service = new SegmentReconcileService($connection, $buffer, $checkpoint, new NullLogger());
        $stats = $service->reconcile();

        $this->assertSame(['added' => 2, 'removed' => 2, 'total_contacts' => 3], $stats);

        $buffer->flush();
        $rows = $this->flushBufferAndGetNormalizedEventRows($buffer);

        // Expected: 4 events (2 add + 2 remove)
        $this->assertCount(4, $rows);

        // Collect events by contact+list for easy assertion
        $byKey = [];
        $bySegIds = [];
        foreach ($rows as $r) {
            $byKey[$r['contact_id'].':'.$r['list_id']] = $r['list_added'];
            $bySegIds[$r['contact_id'].':'.$r['list_id']] = $r['segment_ids'];
        }

        $this->assertTrue($byKey['2:30']);
        $this->assertTrue($byKey['3:10']);
        $this->assertFalse($byKey['1:20']);
        $this->assertFalse($byKey['2:10']);

        // Verify full segment_ids: contact 2 currently has [30], contact 1 has [10], contact 3 has [10]
        $this->assertSame([30], $bySegIds['2:30']);
        $this->assertSame([30], $bySegIds['2:10']);
        $this->assertSame([10], $bySegIds['1:20']);
        $this->assertSame([10], $bySegIds['3:10']);
    }

    /**
     * Manually removed entries (manually_removed = 1) are excluded from the
     * current membership query.
     */
    public function testManuallyRemovedEntriesAreExcluded(): void
    {
        $connection = $this->createMockConnection([]);

        $checkpoint = $this->createEmptyCheckpointStore();
        $buffer = $this->createMauticEventBufferForTests($this->createCapturingEventHttpClient());

        $service = new SegmentReconcileService($connection, $buffer, $checkpoint, new NullLogger());
        $stats = $service->reconcile();

        $this->assertSame(['added' => 0, 'removed' => 0, 'total_contacts' => 0], $stats);
    }

    /**
     * @param list<array<string, int>> $rows rows the initial SELECT (no lead_id IN) returns
     */
    private function createMockConnection(array $rows): Connection
    {
        $conn = $this->createMock(Connection::class);
        // Initial query (loadCurrentMembership) — without lead_id IN
        $conn->method('fetchAllAssociative')
            ->with($this->logicalAnd(
                $this->stringContains('lead_lists_leads'),
                $this->stringContains('manually_removed = 0')
            ))
            ->willReturnCallback(function (string $sql, array $params = []) use ($rows) {
                // If the SQL has lead_id IN, it's the batch membership query
                if (str_contains($sql, 'lead_id IN')) {
                    $contactIds = $params;

                    return array_values(array_filter($rows, function (array $r) use ($contactIds) {
                        return \in_array($r['lead_id'], $contactIds, true);
                    }));
                }

                // Otherwise it's the full membership query
                return $rows;
            });

        return $conn;
    }

    /**
     * @param array<int, list<int>> $memberships
     */
    private function createCheckpointStore(array $memberships): SegmentCheckpointStore
    {
        $store = $this->createMock(SegmentCheckpointStore::class);
        $store->method('load')->willReturn($memberships);
        $store->expects($this->once())->method('save');

        return $store;
    }

    private function createEmptyCheckpointStore(): SegmentCheckpointStore
    {
        return $this->createCheckpointStore([]);
    }
}
