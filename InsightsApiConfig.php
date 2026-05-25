<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle;

/**
 * Outbound Insights API identifiers (no Mautic framework types — safe to load in standalone tests).
 */
final class InsightsApiConfig
{
    public const NAME = 'MauticInsights';

    /** {@see Config/config.php} bundle key / plugin directory name (ReloadHelper maps this to {@see \Mautic\PluginBundle\Entity\Plugin::getBundle()}). */
    public const PLUGIN_BUNDLE = 'MauticInsightsBundle';

    public const HTTP_TOKEN_FIELD = 'http_token';

    /** Web app: API token / instance management (help link in integration credentials). */
    public const APP_INSTANCES_SETTINGS_URL = 'https://mauticinsights.com/settings/instances';

    /**
     * Nested key under {@see \Mautic\PluginBundle\Entity\Integration::getFeatureSettings()} for
     * {@see \Mautic\IntegrationsBundle\Form\Type\IntegrationFeatureSettingsType} child {@code integration}.
     */
    public const FEATURE_SETTINGS_INTEGRATION_SUBKEY = 'integration';

    /**
     * Stored under {@code featureSettings.integration}; 1 = yes, 0 = no (default when unset).
     * When disabled, undelivered outbound events are not written to file / Redis / DB.
     */
    public const FEATURE_PERSISTENT_EVENT_BUFFER = 'persistent_event_buffer';

    /**
     * Stored under featureSettings.integration; 1 = yes, 0 = no (default when unset).
     * When enabled, plugin log lines are forwarded to the main logger; when disabled, they are discarded.
     */
    public const FEATURE_PLUGIN_LOGGING = 'plugin_logging';

    /** Base URL for outbound Mautic Insights API (no trailing slash). */
    public const API_BASE_URL = 'https://api.mauticinsights.com';
    // public const API_BASE_URL = 'https://eoovk3u9orx0b6g.m.pipedream.net';

    public const STATUS_NOTIFY_URL = self::API_BASE_URL.'/status';

    public const EVENT_NOTIFY_URL = self::API_BASE_URL.'/event';

    /** Bulk contact sync endpoint — accepts POST body {@code { "contacts": [...] }}. */
    public const SYNC_CONTACTS_URL = self::API_BASE_URL.'/sync/contacts';

    /**
     * @deprecated segment lifecycle rows use {@see self::EVENT_NOTIFY_URL} with root key {@code events}
     */
    public const SEGMENT_NOTIFY_URL = self::API_BASE_URL.'/segment';
}
