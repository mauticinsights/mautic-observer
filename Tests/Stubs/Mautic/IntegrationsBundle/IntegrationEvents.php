<?php

declare(strict_types=1);

namespace Mautic\IntegrationsBundle;

final class IntegrationEvents
{
    public const INTEGRATION_CONFIG_AFTER_SAVE = 'mautic.integration_config_after_save';

    public const INTEGRATION_API_KEYS_BEFORE_SAVE = 'mautic.integration.INTEGRATION_API_KEYS_BEFORE_SAVE';
}
