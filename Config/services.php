<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use MauticPlugin\MauticInsightsBundle\EventSubscriber\EventBufferFlushSubscriber;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Integration\MauticInsightsIntegration;
use MauticPlugin\MauticInsightsBundle\Service\ConfigurablePersistentEventBuffer;
use MauticPlugin\MauticInsightsBundle\Service\DatabaseEventBuffer;
use MauticPlugin\MauticInsightsBundle\Service\DeferredOutboundEventQueue;
use MauticPlugin\MauticInsightsBundle\Service\DisabledPersistentEventBuffer;
use MauticPlugin\MauticInsightsBundle\Service\FileEventBuffer;
use MauticPlugin\MauticInsightsBundle\Service\InsightsHttpTokenResolver;
use MauticPlugin\MauticInsightsBundle\Service\InsightsLastApiTelemetry;
use MauticPlugin\MauticInsightsBundle\Service\InsightsPluginLogger;
use MauticPlugin\MauticInsightsBundle\Service\InsightsPluginLoggingFeatureReader;
use MauticPlugin\MauticInsightsBundle\Service\InsightsPublishedIntegrationFinder;
use MauticPlugin\MauticInsightsBundle\Service\MauticEventBuffer;
use MauticPlugin\MauticInsightsBundle\Service\RedisEventBuffer;
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

    $serviceScanExcludes = array_merge(
        MauticCoreExtension::DEFAULT_EXCLUDES,
        [
            // Value object; Symfony cannot autowire array $events in __construct().
            'Service/EventBufferLoadResult.php',
            // Wired explicitly with inner logger + feature reader (avoid circular bind on $logger).
            'Service/InsightsPluginLogger.php',
        ]
    );

    $services->load('MauticPlugin\\MauticInsightsBundle\\', '../')
        ->exclude('../{'.implode(',', $serviceScanExcludes).'}');

    $services->alias('mautic.integration.mauticinsights', MauticInsightsIntegration::class);

    $services->set(FileEventBuffer::class)
        ->arg('$path', '%kernel.cache_dir%/mautic_insights_api_events.jsonl');

    $services->set(RedisEventBuffer::class)
        ->arg('$redis', service('redis')->ignoreOnInvalid());

    $services->set(DatabaseEventBuffer::class)
        ->arg('$connection', service('doctrine.dbal.default_connection')->ignoreOnInvalid());

    $services->set(DisabledPersistentEventBuffer::class);

    $services->set(MauticEventBuffer::class)
        ->arg('$integrationFinder', service(InsightsPublishedIntegrationFinder::class))
        ->arg('$httpTokenResolver', service(InsightsHttpTokenResolver::class))
        ->arg('$persistentBuffer', service(ConfigurablePersistentEventBuffer::class))
        ->arg('$flushSize', 50)
        ->arg('$notifyUrl', InsightsApiConfig::EVENT_NOTIFY_URL)
        ->arg('$requestPayloadRootKey', 'events');

    $services->set(InsightsPluginLogger::class)
        ->arg('$inner', service('logger'))
        ->arg('$loggingReader', service(InsightsPluginLoggingFeatureReader::class));

    $services->set(EventBufferFlushSubscriber::class)
        ->arg('$mauticEventBuffer', service(MauticEventBuffer::class))
        ->arg('$deferredOutboundEventQueue', service(DeferredOutboundEventQueue::class));

    $services->set(InsightsLastApiTelemetry::class)
        ->arg('$cacheDir', '%kernel.cache_dir%');
};
