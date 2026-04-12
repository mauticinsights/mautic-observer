<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle;

use Mautic\IntegrationsBundle\Bundle\AbstractPluginBundle;

class MauticInsightsBundle extends AbstractPluginBundle
{
    public function boot(): void
    {
        parent::boot();
        self::registerBufferLegacyClassAliases();
    }

    /**
     * Stale prod DI caches may still reference buffer types under {@code Service\} after they moved to {@code Buffer\}.
     * Inline here so deploys never depend on a separate autoloaded file for this shim.
     */
    private static function registerBufferLegacyClassAliases(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $pairs = [
            'MauticPlugin\\MauticInsightsBundle\\Buffer\\EventBufferLoadResult' => 'MauticPlugin\\MauticInsightsBundle\\Service\\EventBufferLoadResult',
            'MauticPlugin\\MauticInsightsBundle\\Buffer\\PersistentEventBufferInterface' => 'MauticPlugin\\MauticInsightsBundle\\Service\\PersistentEventBufferInterface',
            'MauticPlugin\\MauticInsightsBundle\\Buffer\\DeferredOutboundEventQueue' => 'MauticPlugin\\MauticInsightsBundle\\Service\\DeferredOutboundEventQueue',
            'MauticPlugin\\MauticInsightsBundle\\Buffer\\MauticEventBuffer' => 'MauticPlugin\\MauticInsightsBundle\\Service\\MauticEventBuffer',
            'MauticPlugin\\MauticInsightsBundle\\Buffer\\FileEventBuffer' => 'MauticPlugin\\MauticInsightsBundle\\Service\\FileEventBuffer',
            'MauticPlugin\\MauticInsightsBundle\\Buffer\\DatabaseEventBuffer' => 'MauticPlugin\\MauticInsightsBundle\\Service\\DatabaseEventBuffer',
            'MauticPlugin\\MauticInsightsBundle\\Buffer\\RedisEventBuffer' => 'MauticPlugin\\MauticInsightsBundle\\Service\\RedisEventBuffer',
            'MauticPlugin\\MauticInsightsBundle\\Buffer\\ChainPersistentEventBuffer' => 'MauticPlugin\\MauticInsightsBundle\\Service\\ChainPersistentEventBuffer',
            'MauticPlugin\\MauticInsightsBundle\\Buffer\\ConfigurablePersistentEventBuffer' => 'MauticPlugin\\MauticInsightsBundle\\Service\\ConfigurablePersistentEventBuffer',
            'MauticPlugin\\MauticInsightsBundle\\Buffer\\DisabledPersistentEventBuffer' => 'MauticPlugin\\MauticInsightsBundle\\Service\\DisabledPersistentEventBuffer',
            'MauticPlugin\\MauticInsightsBundle\\Buffer\\PersistentBufferFeatureReader' => 'MauticPlugin\\MauticInsightsBundle\\Service\\PersistentBufferFeatureReader',
        ];

        foreach ($pairs as $canonical => $legacy) {
            if (!\class_exists($legacy, false)) {
                \class_alias($canonical, $legacy);
            }
        }
    }
}
