<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use JsonException;
use Psr\Log\LoggerInterface;

/**
 * Persists API event payloads as JSON lines. Uses a sidecar `.lock` with non-blocking exclusive lock so only
 * one process drains the file; others skip loading the backlog and only handle in-memory "fresh" events.
 */
final class FileEventBuffer
{
    private string $lockPath;

    /** @var resource|null */
    private $lockHandle;

    private bool $enabled;

    public function __construct(
        private string $path,
        private LoggerInterface $logger,
    ) {
        $this->lockPath = $this->path.'.lock';
        $dir = \dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $this->enabled = is_dir($dir) && is_writable($dir);
        if (!$this->enabled) {
            $this->logger->notice('Mautic Insights: file event buffer disabled (directory not writable or could not be created).', ['dir' => $dir]);
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @param list<array<string, mixed>> $payloads
     */
    public function append(array $payloads): void
    {
        if (!$this->enabled || [] === $payloads) {
            return;
        }

        try {
            $lines = [];
            foreach ($payloads as $payload) {
                $lines[] = json_encode($payload, JSON_THROW_ON_ERROR);
            }
            file_put_contents($this->path, implode("\n", $lines)."\n", FILE_APPEND | LOCK_EX);
        } catch (JsonException $e) {
            $this->logger->warning('Mautic Insights: file event buffer append JSON encode failed: '.$e->getMessage(), ['exception' => $e]);
        }
    }

    public function loadAndLock(): EventBufferLoadResult
    {
        if (!$this->enabled) {
            return new EventBufferLoadResult([], false);
        }

        if (!file_exists($this->path)) {
            return new EventBufferLoadResult([], false);
        }

        $this->lockHandle = fopen($this->lockPath, 'wb');
        if (false === $this->lockHandle) {
            $this->logger->warning('Mautic Insights: could not open lock file.', ['path' => $this->lockPath]);

            return new EventBufferLoadResult([], false);
        }

        if (!flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($this->lockHandle);
            $this->lockHandle = null;

            return new EventBufferLoadResult([], false);
        }

        return new EventBufferLoadResult($this->loadAll(), true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadAll(): array
    {
        $raw = @file_get_contents($this->path);
        if (false === $raw || '' === trim($raw)) {
            return [];
        }

        $out = [];
        foreach (preg_split("/\r\n|\n|\r/", $raw) as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (\is_array($decoded)) {
                    $out[] = $decoded;
                }
            } catch (JsonException $e) {
                $this->logger->warning('Mautic Insights: skipping invalid JSON line in event buffer.', ['exception' => $e]);
            }
        }

        return $out;
    }

    public function clear(): void
    {
        if (null === $this->lockHandle) {
            return;
        }

        if (file_exists($this->path)) {
            if (!@unlink($this->path)) {
                $this->logger->warning('Mautic Insights: could not unlink event buffer file.', ['path' => $this->path]);
            }
        }

        flock($this->lockHandle, LOCK_UN);
        fclose($this->lockHandle);
        $this->lockHandle = null;
    }
}
