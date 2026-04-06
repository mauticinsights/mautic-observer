<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests;

use MauticPlugin\MauticInsightsBundle\Service\FileEventBuffer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests FileEventBuffer including the auto-mkdir behaviour added when moving from
 * kernel.cache_dir (wiped by cache:clear) to var/mauticinsights-buffer.
 */
final class FileEventBufferTest extends TestCase
{
    /** @var list<string> Temp dirs created by tests; removed in tearDown. */
    private array $dirsToCleanup = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->dirsToCleanup) as $dir) {
            if (is_dir($dir)) {
                // Make writable again in case a test chmod-ed it.
                @chmod($dir, 0755);
                $this->removeDir($dir);
            }
        }
    }

    // ------------------------------------------------------------------
    // Auto-mkdir
    // ------------------------------------------------------------------

    public function testCreatesDirectoryWhenItDoesNotExist(): void
    {
        $base = sys_get_temp_dir().'/mi-buf-test-autocreate-'.uniqid('', true);
        $this->dirsToCleanup[] = $base;

        $buffer = new FileEventBuffer($base.'/events.jsonl', new NullLogger());

        $this->assertTrue($buffer->isEnabled(), 'Buffer should be enabled after auto-mkdir.');
        $this->assertDirectoryExists($base);
    }

    public function testCreatesNestedDirectoryWhenItDoesNotExist(): void
    {
        $base = sys_get_temp_dir().'/mi-buf-test-nested-'.uniqid('', true);
        $this->dirsToCleanup[] = $base;

        $buffer = new FileEventBuffer($base.'/a/b/c/events.jsonl', new NullLogger());

        $this->assertTrue($buffer->isEnabled());
        $this->assertDirectoryExists($base.'/a/b/c');
    }

    public function testDisabledWhenDirectoryCannotBeCreated(): void
    {
        // Use an existing *file* as a fake parent dir so mkdir() will fail.
        $fakeParent = tempnam(sys_get_temp_dir(), 'mi-buf-test-notadir-');
        $this->dirsToCleanup[] = $fakeParent; // tempnam leaves a file; clean up via removeDir that checks is_dir

        $buffer = new FileEventBuffer($fakeParent.'/subdir/events.jsonl', new NullLogger());

        $this->assertFalse($buffer->isEnabled(), 'Buffer must be disabled when the dir cannot be created.');
    }

    // ------------------------------------------------------------------
    // Basic round-trip: append → loadAndLock → clear
    // ------------------------------------------------------------------

    public function testAppendLoadClear(): void
    {
        $base = sys_get_temp_dir().'/mi-buf-test-roundtrip-'.uniqid('', true);
        $this->dirsToCleanup[] = $base;

        $buffer = new FileEventBuffer($base.'/events.jsonl', new NullLogger());
        $this->assertTrue($buffer->isEnabled());

        $buffer->append([
            ['event' => 'page.hit', 'ts' => '2026-01-01T00:00:00+00:00', 'count' => 1],
            ['event' => 'lead.save', 'ts' => '2026-01-01T00:00:01+00:00', 'count' => 3],
        ]);

        $result = $buffer->loadAndLock();
        $this->assertTrue($result->lockHeld);
        $this->assertCount(2, $result->events);
        $this->assertSame('page.hit', $result->events[0]['event']);
        $this->assertSame('lead.save', $result->events[1]['event']);

        $buffer->clear();

        // After clear the file is gone; second loadAndLock returns empty without lock.
        $again = $buffer->loadAndLock();
        $this->assertFalse($again->lockHeld);
        $this->assertSame([], $again->events);
    }

    public function testMultipleAppendsAccumulate(): void
    {
        $base = sys_get_temp_dir().'/mi-buf-test-multi-'.uniqid('', true);
        $this->dirsToCleanup[] = $base;

        $buffer = new FileEventBuffer($base.'/events.jsonl', new NullLogger());

        $buffer->append([['event' => 'a', 'ts' => '2026-01-01T00:00:00+00:00', 'count' => 1]]);
        $buffer->append([['event' => 'b', 'ts' => '2026-01-01T00:00:01+00:00', 'count' => 2]]);
        $buffer->append([['event' => 'c', 'ts' => '2026-01-01T00:00:02+00:00', 'count' => 3]]);

        $result = $buffer->loadAndLock();
        $this->assertTrue($result->lockHeld);
        $this->assertCount(3, $result->events);
        $buffer->clear();
    }

    // ------------------------------------------------------------------
    // Resilience: content survives simulated "cache:clear"
    // (the whole point of moving away from kernel.cache_dir)
    // ------------------------------------------------------------------

    public function testContentSurvivesUnrelatedDirectoryDeletion(): void
    {
        $bufferDir = sys_get_temp_dir().'/mi-buf-test-survive-'.uniqid('', true);
        $cacheDir = sys_get_temp_dir().'/mi-buf-test-cache-'.uniqid('', true);
        $this->dirsToCleanup[] = $bufferDir;
        $this->dirsToCleanup[] = $cacheDir;

        mkdir($cacheDir, 0755, true);
        $buffer = new FileEventBuffer($bufferDir.'/events.jsonl', new NullLogger());

        $buffer->append([['event' => 'important', 'ts' => '2026-01-01T00:00:00+00:00', 'count' => 99]]);

        // Simulate "cache:clear": wipe the cache directory completely.
        $this->removeDir($cacheDir);
        $this->assertDirectoryDoesNotExist($cacheDir, 'Cache dir should be gone.');

        // Buffer in its own dir must still be intact.
        $this->assertDirectoryExists($bufferDir, 'Buffer dir must survive cache clear.');

        $result = $buffer->loadAndLock();
        $this->assertTrue($result->lockHeld);
        $this->assertCount(1, $result->events);
        $this->assertSame(99, $result->events[0]['count']);
        $buffer->clear();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            if (file_exists($dir)) {
                @unlink($dir);
            }

            return;
        }

        foreach (scandir($dir) as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
