<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Persists buffered events in a dedicated table. Used when file and Redis buffers are unavailable.
 * Uses MySQL GET_LOCK / PostgreSQL advisory lock / flock (SQLite and others) around drain.
 */
final class DatabaseEventBuffer
{
    private bool $tableEnsured = false;

    /** @var list<int|string>|null */
    private ?array $loadedIds = null;

    private bool $backendLockHeld = false;

    /** @var resource|null */
    private $flockHandle;

    private string $flockPath;

    public function __construct(
        private ?Connection $connection,
        private LoggerInterface $logger,
        private string $tableName = 'plugin_mautic_insights_api_events',
        private string $mysqlLockName = 'mautic_insights_api_evbuf',
    ) {
        if (null !== $connection) {
            $params = $connection->getParams();
            $prefixRaw = $params['table_prefix'] ?? null;
            $prefix = \is_string($prefixRaw) ? $prefixRaw : '';
            if ('' !== $prefix) {
                $this->tableName = $prefix.$this->tableName;
            }
        }
        $this->flockPath = sys_get_temp_dir().'/mautic-insights-db-evbuf-'.hash('sha256', $this->tableName).'.lock';
    }

    public function isEnabled(): bool
    {
        return null !== $this->connection;
    }

    /**
     * @param list<array<string, mixed>> $payloads
     */
    public function append(array $payloads): void
    {
        if (null === $this->connection || [] === $payloads) {
            return;
        }

        try {
            $this->ensureTable();
        } catch (Throwable $e) {
            $this->logger->warning('Mautic Insights: database event buffer ensureTable failed: '.$e->getMessage(), ['exception' => $e]);

            return;
        }

        $utc = new DateTimeZone('UTC');
        foreach ($payloads as $payload) {
            try {
                $this->connection->insert($this->resolveTableName(), [
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'date_added' => new DateTimeImmutable('now', $utc),
                ], [
                    'payload' => Types::TEXT,
                    'date_added' => Types::DATETIME_IMMUTABLE,
                ]);
            } catch (JsonException $e) {
                $this->logger->warning('Mautic Insights: database event buffer JSON encode failed: '.$e->getMessage(), ['exception' => $e]);
            } catch (Throwable $e) {
                $this->logger->warning('Mautic Insights: database event buffer insert failed: '.$e->getMessage(), ['exception' => $e]);
            }
        }
    }

    public function loadAndLock(): EventBufferLoadResult
    {
        $this->loadedIds = null;
        $this->backendLockHeld = false;

        if (null === $this->connection) {
            return new EventBufferLoadResult([], false);
        }

        try {
            $this->ensureTable();
        } catch (Throwable $e) {
            $this->logger->warning('Mautic Insights: database event buffer ensureTable failed: '.$e->getMessage(), ['exception' => $e]);

            return new EventBufferLoadResult([], false);
        }

        if (!$this->acquireBackendLock()) {
            return new EventBufferLoadResult([], false);
        }

        $this->backendLockHeld = true;

        try {
            $t = $this->connection->quoteIdentifier($this->resolveTableName());
            $rows = $this->connection->fetchAllAssociative("SELECT id, payload FROM {$t} ORDER BY id ASC");
        } catch (Throwable $e) {
            $this->logger->warning('Mautic Insights: database event buffer SELECT failed: '.$e->getMessage(), ['exception' => $e]);
            $this->releaseBackendLock();

            return new EventBufferLoadResult([], false);
        }

        if ([] === $rows) {
            $this->releaseBackendLock();

            return new EventBufferLoadResult([], false);
        }

        $events = [];
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $row['id'];
            try {
                $decoded = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
                if (\is_array($decoded)) {
                    $events[] = $decoded;
                }
            } catch (JsonException $e) {
                $this->logger->warning('Mautic Insights: skipping invalid JSON row in database event buffer.', ['exception' => $e]);
            }
        }

        $this->loadedIds = $ids;

        return new EventBufferLoadResult($events, true);
    }

    public function clear(): void
    {
        if (null === $this->connection) {
            return;
        }

        try {
            if (null !== $this->loadedIds && [] !== $this->loadedIds) {
                $t = $this->connection->quoteIdentifier($this->resolveTableName());
                $placeholders = implode(',', array_fill(0, \count($this->loadedIds), '?'));
                $this->connection->executeStatement(
                    "DELETE FROM {$t} WHERE id IN ({$placeholders})",
                    array_values($this->loadedIds),
                    array_fill(0, \count($this->loadedIds), ParameterType::STRING)
                );
            }
        } catch (Throwable $e) {
            $this->logger->warning('Mautic Insights: database event buffer DELETE failed: '.$e->getMessage(), ['exception' => $e]);
        }

        $this->loadedIds = null;
        $this->releaseBackendLock();
    }

    private function resolveTableName(): string
    {
        return $this->tableName;
    }

    /**
     * @throws DbalException
     */
    private function ensureTable(): void
    {
        if ($this->tableEnsured || null === $this->connection) {
            return;
        }

        $sm = $this->connection->createSchemaManager();
        $name = $this->resolveTableName();
        if ($sm->tablesExist([$name])) {
            $this->tableEnsured = true;

            return;
        }

        $table = new Table($name);
        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true]);
        $table->addColumn('payload', Types::TEXT, ['notnull' => true]);
        $table->addColumn('date_added', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $table->setPrimaryKey(['id']);
        $sm->createTable($table);
        $this->tableEnsured = true;
    }

    private function acquireBackendLock(): bool
    {
        if (null === $this->connection) {
            return false;
        }

        $platform = $this->connection->getDatabasePlatform();

        try {
            if ($platform instanceof AbstractMySQLPlatform) {
                $r = $this->connection->fetchOne('SELECT GET_LOCK(?, 0)', [$this->mysqlLockName]);

                return 1 === (int) $r;
            }

            if ($platform instanceof PostgreSQLPlatform) {
                $r = $this->connection->fetchOne('SELECT pg_try_advisory_lock(712900001, 712900002)');

                return (bool) $r;
            }

            return $this->acquireFlock();
        } catch (Throwable $e) {
            $this->logger->notice('Mautic Insights: database event buffer backend lock failed: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    private function releaseBackendLock(): void
    {
        if (!$this->backendLockHeld) {
            $this->releaseFlockOnly();

            return;
        }

        if (null !== $this->connection) {
            $platform = $this->connection->getDatabasePlatform();

            try {
                if ($platform instanceof AbstractMySQLPlatform) {
                    $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$this->mysqlLockName]);
                } elseif ($platform instanceof PostgreSQLPlatform) {
                    $this->connection->executeStatement('SELECT pg_advisory_unlock(712900001, 712900002)');
                }
            } catch (Throwable) {
            }
        }

        $this->releaseFlockOnly();
        $this->backendLockHeld = false;
    }

    private function acquireFlock(): bool
    {
        $h = @fopen($this->flockPath, 'cb');
        if (false === $h) {
            return false;
        }

        if (!flock($h, LOCK_EX | LOCK_NB)) {
            fclose($h);

            return false;
        }

        $this->flockHandle = $h;

        return true;
    }

    private function releaseFlockOnly(): void
    {
        if (null !== $this->flockHandle && \is_resource($this->flockHandle)) {
            flock($this->flockHandle, LOCK_UN);
            fclose($this->flockHandle);
        }
        $this->flockHandle = null;
    }
}
