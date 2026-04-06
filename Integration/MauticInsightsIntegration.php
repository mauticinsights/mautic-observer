<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Integration;

use Mautic\IntegrationsBundle\Integration\BasicIntegration;
use Mautic\IntegrationsBundle\Integration\Interfaces\BasicInterface;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormAuthInterface;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticInsightsBundle\Form\Type\AuthConfigType;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;

class MauticInsightsIntegration extends BasicIntegration implements BasicInterface, ConfigFormAuthInterface
{
    public const NAME = InsightsApiConfig::NAME;

    /** Stored in {@see Integration::getApiKeys()} under this key. */
    public const HTTP_TOKEN_FIELD = InsightsApiConfig::HTTP_TOKEN_FIELD;

    public const STATUS_NOTIFY_URL = InsightsApiConfig::STATUS_NOTIFY_URL;

    public const EVENT_NOTIFY_URL = InsightsApiConfig::EVENT_NOTIFY_URL;

    public const SEGMENT_NOTIFY_URL = InsightsApiConfig::SEGMENT_NOTIFY_URL;

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDisplayName(): string
    {
        return 'Mautic Insights';
    }

    public function getIcon(): string
    {
        return 'plugins/MauticInsightsBundle/Assets/img/logo.svg';
    }

    public function getAuthConfigFormName(): string
    {
        return AuthConfigType::class;
    }
}
