<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Mautic\CoreBundle\Model\NotificationModel;
use Mautic\PluginBundle\Entity\Integration;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * When the Insights event API repeatedly fails: exponential backoff between flush attempts, then after
 * {@see self::OUTAGE_DISABLE_AFTER_SECONDS} of continuous outage unpublishes the integration and notifies admins.
 */
final class InsightsApiOutageCoordinator implements InsightsApiSendGate
{
    public const OUTAGE_DISABLE_AFTER_SECONDS = 86400;

    private const BACKOFF_INITIAL_SECONDS = 60;

    private const BACKOFF_MAX_SECONDS = 3600;

    private const NOTIFICATION_DEDUP_KEY = 'mauticinsights_api_outage_auto_disable';

    public function __construct(
        private string $dataDir,
        private EntityManagerInterface $entityManager,
        private NotificationModel $notificationModel,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
        if (!is_dir($this->dataDir)) {
            @mkdir($this->dataDir, 0755, true);
        }
    }

    public function tick(): void
    {
        $this->withExclusiveFileLock(function (): void {
            $state = $this->readStateUnlocked();
            if (null !== ($state['shutdown_notified_at'] ?? null)) {
                return;
            }
            $began = $state['outage_began_at'] ?? null;
            if (!\is_string($began) || '' === $began) {
                return;
            }
            try {
                $start = new DateTimeImmutable($began);
            } catch (Throwable) {
                return;
            }
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            if ($now->getTimestamp() - $start->getTimestamp() < self::OUTAGE_DISABLE_AFTER_SECONDS) {
                return;
            }

            try {
                $this->unpublishIntegrationAndNotifyAdminsUnlocked();
            } catch (Throwable $e) {
                $this->logger->critical(
                    'Mautic Insights: prolonged API outage handling failed: '.$e->getMessage(),
                    ['exception' => $e]
                );

                return;
            }

            $state['shutdown_notified_at'] = $now->format(\DATE_ATOM);
            $state['outage_began_at'] = null;
            $state['consecutive_failed_flushes'] = 0;
            $state['next_attempt_epoch'] = null;
            $this->writeStateUnlocked($state);
        });
    }

    public function shouldSkipHttpThisFlush(): bool
    {
        return $this->withExclusiveFileLock(function (): bool {
            $state = $this->readStateUnlocked();
            $next = $state['next_attempt_epoch'] ?? null;
            if (null === $next || !\is_int($next)) {
                return false;
            }

            return time() < $next;
        });
    }

    public function afterFlush(bool $httpAttempted, bool $everyChunkSucceeded): void
    {
        if (!$httpAttempted) {
            return;
        }

        $this->withExclusiveFileLock(function () use ($everyChunkSucceeded): void {
            $state = $this->readStateUnlocked();
            if (null !== ($state['shutdown_notified_at'] ?? null)) {
                return;
            }

            if ($everyChunkSucceeded) {
                $state['outage_began_at'] = null;
                $state['consecutive_failed_flushes'] = 0;
                $state['next_attempt_epoch'] = null;
                $this->writeStateUnlocked($state);

                return;
            }

            if (null === ($state['outage_began_at'] ?? null) || !\is_string($state['outage_began_at'])) {
                $state['outage_began_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(\DATE_ATOM);
            }

            $n = (int) ($state['consecutive_failed_flushes'] ?? 0) + 1;
            $state['consecutive_failed_flushes'] = $n;
            $exp = min($n - 1, 16);
            $delay = min(self::BACKOFF_MAX_SECONDS, (int) round(self::BACKOFF_INITIAL_SECONDS * (2 ** $exp)));
            $state['next_attempt_epoch'] = time() + $delay;
            $this->writeStateUnlocked($state);
        });
    }

    public function resetStateOnIntegrationConfigChange(): void
    {
        $this->withExclusiveFileLock(function (): void {
            $this->writeStateUnlocked($this->emptyState());
        });
    }

    private function unpublishIntegrationAndNotifyAdminsUnlocked(): void
    {
        $integration = $this->entityManager->getRepository(Integration::class)->findOneBy(['name' => InsightsApiConfig::NAME]);
        if ($integration instanceof Integration && $integration->getIsPublished()) {
            $integration->setIsPublished(false);
            $this->entityManager->flush();
        }

        $header = $this->translator->trans('mautic.mauticinsights.outage.notification.header');
        $message = $this->translator->trans('mautic.mauticinsights.outage.notification.body');
        $dedupeFrom = new DateTime('-30 days');

        $users = $this->entityManager->getRepository(User::class)->findAll();
        foreach ($users as $user) {
            if (!$user instanceof User || !$user->getId() || !$user->isAdmin()) {
                continue;
            }
            try {
                $this->notificationModel->addNotification(
                    $message,
                    'error',
                    false,
                    $header,
                    'ri-plug-line',
                    new DateTime('now', new DateTimeZone('UTC')),
                    $user,
                    self::NOTIFICATION_DEDUP_KEY,
                    $dedupeFrom
                );
            } catch (Throwable $e) {
                $this->logger->warning(
                    'Mautic Insights: could not add outage notification for user '.$user->getId().': '.$e->getMessage(),
                    ['exception' => $e]
                );
            }
        }
    }

    /**
     * @return array{outage_began_at: ?string, consecutive_failed_flushes: int, next_attempt_epoch: ?int, shutdown_notified_at: ?string}
     */
    private function emptyState(): array
    {
        return [
            'outage_began_at' => null,
            'consecutive_failed_flushes' => 0,
            'next_attempt_epoch' => null,
            'shutdown_notified_at' => null,
        ];
    }

    /**
     * @return array{outage_began_at: ?string, consecutive_failed_flushes: int, next_attempt_epoch: ?int, shutdown_notified_at: ?string}
     */
    private function readStateUnlocked(): array
    {
        $path = $this->statePath();
        if (!is_readable($path)) {
            return $this->emptyState();
        }
        $raw = @file_get_contents($path);
        if (false === $raw || '' === trim($raw)) {
            return $this->emptyState();
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->emptyState();
        }
        if (!\is_array($decoded)) {
            return $this->emptyState();
        }

        $outageBegan = $decoded['outage_began_at'] ?? null;
        $shutdown = $decoded['shutdown_notified_at'] ?? null;
        $next = $decoded['next_attempt_epoch'] ?? null;

        return [
            'outage_began_at' => \is_string($outageBegan) && '' !== $outageBegan ? $outageBegan : null,
            'consecutive_failed_flushes' => max(0, (int) ($decoded['consecutive_failed_flushes'] ?? 0)),
            'next_attempt_epoch' => null !== $next ? (int) $next : null,
            'shutdown_notified_at' => \is_string($shutdown) && '' !== $shutdown ? $shutdown : null,
        ];
    }

    /**
     * @param array{outage_began_at: ?string, consecutive_failed_flushes: int, next_attempt_epoch: ?int, shutdown_notified_at: ?string} $state
     */
    private function writeStateUnlocked(array $state): void
    {
        try {
            file_put_contents(
                $this->statePath(),
                json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
                LOCK_EX
            );
        } catch (JsonException $e) {
            $this->logger->warning('Mautic Insights: could not encode outage state: '.$e->getMessage(), ['exception' => $e]);
        } catch (Throwable $e) {
            $this->logger->warning('Mautic Insights: could not write outage state: '.$e->getMessage(), ['exception' => $e]);
        }
    }

    private function statePath(): string
    {
        return $this->dataDir.'/mautic_insights_api_outage.json';
    }

    private function lockPath(): string
    {
        return $this->dataDir.'/mautic_insights_api_outage.lock';
    }

    /**
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    private function withExclusiveFileLock(callable $fn)
    {
        $h = @fopen($this->lockPath(), 'cb');
        if (false === $h) {
            return $fn();
        }
        flock($h, LOCK_EX);
        try {
            return $fn();
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }
}
