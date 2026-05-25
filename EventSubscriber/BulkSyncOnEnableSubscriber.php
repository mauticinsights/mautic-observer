<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\EventSubscriber;

use Mautic\IntegrationsBundle\Event\ConfigSaveEvent;
use Mautic\IntegrationsBundle\IntegrationEvents;
use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Event\PluginInstallEvent;
use Mautic\PluginBundle\Event\PluginIntegrationEvent;
use Mautic\PluginBundle\Event\PluginUpdateEvent;
use Mautic\PluginBundle\PluginEvents;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Service\BulkSyncService;
use MauticPlugin\MauticInsightsBundle\Service\InsightsHttpTokenResolver;
use MauticPlugin\MauticInsightsBundle\Service\InsightsPublishedIntegrationFinder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * Triggers a bulk sync of all Mautic entities (segments, emails, campaigns, contacts) only when
 * the plugin transitions from disabled → enabled (is_published: false → true).
 *
 * The sync is queued on the event and executed on {@code kernel.terminate} /
 * {@code console.terminate} so the HTTP response to the admin is not delayed.
 *
 * Sync is idempotent: re-running it updates existing records without creating duplicates.
 * New contacts get a contact_snapshot row (+1 identified); existing contacts are profile-updated only.
 */
final class BulkSyncOnEnableSubscriber implements EventSubscriberInterface
{
    private const STATE_FILE = 'plugin_published_state.json';

    private bool $syncQueued = false;

    public function __construct(
        private BulkSyncService $bulkSyncService,
        private InsightsPublishedIntegrationFinder $integrationFinder,
        private InsightsHttpTokenResolver $httpTokenResolver,
        private LoggerInterface $logger,
        private string $dataDir = '',
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            IntegrationEvents::INTEGRATION_CONFIG_AFTER_SAVE => ['onConfigSave', 0],
            PluginEvents::PLUGIN_ON_INTEGRATION_CONFIG_SAVE => ['onPluginIntegrationConfigSave', 0],
            PluginEvents::ON_PLUGIN_INSTALL => ['onPluginInstall', 0],
            PluginEvents::ON_PLUGIN_UPDATE => ['onPluginUpdate', 0],
            KernelEvents::TERMINATE => ['onHttpTerminate', -2048],
            ConsoleEvents::TERMINATE => ['onConsoleTerminate', -2048],
        ];
    }

    public function onConfigSave(ConfigSaveEvent $event): void
    {
        $this->queueSyncIfInsightsIntegration($event->getIntegrationConfiguration());
    }

    public function onPluginIntegrationConfigSave(PluginIntegrationEvent $event): void
    {
        $this->queueSyncIfInsightsIntegration($event->getEntity());
    }

    public function onPluginInstall(PluginInstallEvent $event): void
    {
        if (InsightsApiConfig::PLUGIN_BUNDLE !== $event->getPlugin()->getBundle()) {
            return;
        }

        $this->queueSyncFromFinder();
    }

    public function onPluginUpdate(PluginUpdateEvent $event): void
    {
        if (InsightsApiConfig::PLUGIN_BUNDLE !== $event->getPlugin()->getBundle()) {
            return;
        }

        $this->queueSyncFromFinder();
    }

    public function onHttpTerminate(TerminateEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->runQueuedSync();
    }

    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        $this->runQueuedSync();
    }

    private function queueSyncIfInsightsIntegration(Integration $integration): void
    {
        if (InsightsApiConfig::NAME !== $integration->getName()) {
            return;
        }

        $isNowPublished = $integration->getIsPublished();
        $wasPublished = $this->readPublishedState();
        $this->writePublishedState($isNowPublished);

        if (!$isNowPublished) {
            return;
        }

        if ($wasPublished) {
            return;
        }

        $token = $this->httpTokenResolver->resolve($integration);
        if ('' === $token) {
            return;
        }

        $this->syncQueued = true;
    }

    private function queueSyncFromFinder(): void
    {
        $integration = $this->integrationFinder->findPublished();
        if (null === $integration) {
            return;
        }

        $token = $this->httpTokenResolver->resolve($integration);
        if ('' === $token) {
            return;
        }

        $this->syncQueued = true;
    }

    private function readPublishedState(): bool
    {
        if ('' === $this->dataDir) {
            return false;
        }

        $path = $this->dataDir.'/'.self::STATE_FILE;
        if (!is_file($path)) {
            return false;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return \is_array($data) && isset($data['is_published']) && (bool) $data['is_published'];
    }

    private function writePublishedState(bool $isPublished): void
    {
        if ('' === $this->dataDir) {
            return;
        }

        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }

        file_put_contents(
            $this->dataDir.'/'.self::STATE_FILE,
            json_encode(['is_published' => $isPublished], JSON_THROW_ON_ERROR),
        );
    }

    private function runQueuedSync(): void
    {
        if (!$this->syncQueued) {
            return;
        }

        $this->syncQueued = false;

        try {
            $count = $this->bulkSyncService->syncAll();
            $this->logger->info('MauticInsights BulkSync: finished.', ['entities_synced' => $count]);
        } catch (Throwable $e) {
            $this->logger->error(
                'MauticInsights BulkSync: unexpected error during sync: '.$e->getMessage(),
                ['exception' => $e],
            );
        }
    }
}
