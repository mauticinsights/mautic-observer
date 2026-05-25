<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use Mautic\IntegrationsBundle\Facade\EncryptionService;
use MauticPlugin\MauticInsightsBundle\Buffer\ConfigurablePersistentEventBuffer;
use MauticPlugin\MauticInsightsBundle\Buffer\DatabaseEventBuffer;
use MauticPlugin\MauticInsightsBundle\Buffer\DeferredOutboundEventQueue;
use MauticPlugin\MauticInsightsBundle\Buffer\DisabledPersistentEventBuffer;
use MauticPlugin\MauticInsightsBundle\Buffer\FileEventBuffer;
use MauticPlugin\MauticInsightsBundle\Buffer\MauticEventBuffer;
use MauticPlugin\MauticInsightsBundle\Buffer\RedisEventBuffer;
use MauticPlugin\MauticInsightsBundle\EventSubscriber\BulkSyncOnEnableSubscriber;
use MauticPlugin\MauticInsightsBundle\EventSubscriber\EventBufferFlushSubscriber;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Integration\MauticInsightsIntegration;
use MauticPlugin\MauticInsightsBundle\Service\InsightsApiOutageCoordinator;
use MauticPlugin\MauticInsightsBundle\Service\InsightsApiSendGate;
use MauticPlugin\MauticInsightsBundle\Service\InsightsLastApiTelemetry;
use MauticPlugin\MauticInsightsBundle\Service\InsightsOutboundTokenProvider;
use MauticPlugin\MauticInsightsBundle\Service\InsightsPluginLogger;
use MauticPlugin\MauticInsightsBundle\Service\InsightsPluginLoggingFeatureReader;
use MauticPlugin\MauticInsightsBundle\Service\SafeEncryptionService;
use MauticPlugin\MauticInsightsBundle\Service\SyncSessionManager;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public()
        ->bind('$lastApiTelemetry', service(InsightsLastApiTelemetry::class))
        ->bind('$logger', service(InsightsPluginLogger::class));

    $services->set(InsightsApiOutageCoordinator::class)
        ->arg('$dataDir', '%kernel.project_dir%/var/mauticinsights-buffer');

    $services->alias(InsightsApiSendGate::class, InsightsApiOutageCoordinator::class);

    $serviceScanExcludes = array_merge(
        MauticCoreExtension::DEFAULT_EXCLUDES,
        [
            // Value object; Symfony cannot autowire array $events in __construct().
            'Buffer/EventBufferLoadResult.php',
            // Wired explicitly with inner logger + feature reader (avoid circular bind on $logger).
            'Service/InsightsPluginLogger.php',
            // $dataDir must be explicit; prototype load would overwrite an earlier ->set().
            'Service/InsightsApiOutageCoordinator.php',
            // $dataDir must be explicit; also avoids accidental EntityManagerInterface injection.
            'Service/SyncSessionManager.php',
            // $dataDir must be explicit for plugin_published_state.json tracking.
            'EventSubscriber/BulkSyncOnEnableSubscriber.php',
            // Registered explicitly as override for EncryptionService::class.
            'Service/SafeEncryptionService.php',
        ]
    );

    $services->load('MauticPlugin\\MauticInsightsBundle\\', '../')
        ->exclude('../{'.implode(',', $serviceScanExcludes).'}');

    $services->alias('mautic.integration.mauticinsights', MauticInsightsIntegration::class);

    $services->set(FileEventBuffer::class)
        ->arg('$path', '%kernel.project_dir%/var/mauticinsights-buffer/mautic_insights_api_events.jsonl');

    $services->set(RedisEventBuffer::class)
        ->arg('$redis', service('redis')->ignoreOnInvalid());

    $services->set(DatabaseEventBuffer::class)
        ->arg('$connection', service('doctrine.dbal.default_connection')->ignoreOnInvalid());

    $services->set(DisabledPersistentEventBuffer::class);

    $services->set(InsightsOutboundTokenProvider::class)
        ->arg('$logger', service(InsightsPluginLogger::class));

    $services->set(SyncSessionManager::class)
        ->arg('$dataDir', '%kernel.project_dir%/var/mauticinsights-buffer')
        ->arg('$logger', service(InsightsPluginLogger::class));

    $services->set(MauticEventBuffer::class)
        ->arg('$outboundTokenProvider', service(InsightsOutboundTokenProvider::class))
        ->arg('$persistentBuffer', service(ConfigurablePersistentEventBuffer::class))
        ->arg('$sendGate', service(InsightsApiSendGate::class))
        ->arg('$flushSize', 50)
        ->arg('$notifyUrl', InsightsApiConfig::EVENT_NOTIFY_URL)
        ->arg('$requestPayloadRootKey', 'events')
        ->arg('$syncSessionManager', service(SyncSessionManager::class));

    $services->set(InsightsPluginLogger::class)
        ->arg('$inner', service('logger'))
        ->arg('$loggingReader', service(InsightsPluginLoggingFeatureReader::class));

    $services->set(EventBufferFlushSubscriber::class)
        ->arg('$mauticEventBuffer', service(MauticEventBuffer::class))
        ->arg('$deferredOutboundEventQueue', service(DeferredOutboundEventQueue::class))
        ->arg('$insightsApiSendGate', service(InsightsApiSendGate::class));

    $services->set(InsightsLastApiTelemetry::class)
        ->arg('$dataDir', '%kernel.project_dir%/var/mauticinsights-buffer');

    // Override IntegrationsBundle's EncryptionService with a safe subclass that handles
    // empty strings and plain-text values gracefully (PHP 8.2 TypeError prevention).
    $services->set(EncryptionService::class, SafeEncryptionService::class);

    $services->set(BulkSyncOnEnableSubscriber::class)
        ->arg('$dataDir', '%kernel.project_dir%/var/mauticinsights-buffer');
};
