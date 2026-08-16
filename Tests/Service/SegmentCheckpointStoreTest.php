<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\Service;

use MauticPlugin\MauticInsightsBundle\Service\SegmentCheckpointStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SegmentCheckpointStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/mi_checkpoint_test_'.uniqid('', true);
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->dir);
    }

    public function testLoadReturnsEmptyArrayWhenFileDoesNotExist(): void
    {
        $store = new SegmentCheckpointStore($this->dir, new NullLogger());
        $this->assertSame([], $store->load());
    }

    public function testLoadReturnsEmptyArrayWhenFileIsEmpty(): void
    {
        file_put_contents($this->dir.'/segment_checkpoint.json', '');
        $store = new SegmentCheckpointStore($this->dir, new NullLogger());
        $this->assertSame([], $store->load());
    }

    public function testLoadReturnsEmptyArrayWhenJsonIsInvalid(): void
    {
        file_put_contents($this->dir.'/segment_checkpoint.json', '{broken');
        $store = new SegmentCheckpointStore($this->dir, new NullLogger());
        $this->assertSame([], $store->load());
    }

    public function testLoadReturnsEmptyArrayWhenStructureIsWrong(): void
    {
        file_put_contents($this->dir.'/segment_checkpoint.json', json_encode(['version' => 1]));
        $store = new SegmentCheckpointStore($this->dir, new NullLogger());
        $this->assertSame([], $store->load());
    }

    public function testSaveAndLoadRoundTrip(): void
    {
        $store = new SegmentCheckpointStore($this->dir, new NullLogger());

        $memberships = [
            1 => [10, 20],
            42 => [5],
        ];

        $store->save($memberships);
        $loaded = $store->load();

        $this->assertSame($memberships, $loaded);
    }

    public function testSaveIsAtomic(): void
    {
        $store = new SegmentCheckpointStore($this->dir, new NullLogger());

        $store->save([1 => [10]]);
        $this->assertSame([1 => [10]], $store->load());

        $store->save([2 => [20]]);
        $this->assertSame([2 => [20]], $store->load());

        $this->assertFileDoesNotExist($this->dir.'/segment_checkpoint.json.tmp');
    }

    public function testLoadStripsInvalidContactIds(): void
    {
        file_put_contents(
            $this->dir.'/segment_checkpoint.json',
            json_encode([
                'version' => 1,
                'updated_at' => '2026-01-01T00:00:00Z',
                'memberships' => [
                    '0' => [1],
                    '-1' => [2],
                    '42' => [10],
                ],
            ]),
        );

        $store = new SegmentCheckpointStore($this->dir, new NullLogger());
        $this->assertSame([42 => [10]], $store->load());
    }

    public function testCreatesDirectoryOnSave(): void
    {
        $nestedDir = $this->dir.'/nested/sub';
        $store = new SegmentCheckpointStore($nestedDir, new NullLogger());

        $store->save([1 => [5]]);
        $this->assertFileExists($nestedDir.'/segment_checkpoint.json');
        $this->assertSame([1 => [5]], $store->load());
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->rmdirRecursive($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
