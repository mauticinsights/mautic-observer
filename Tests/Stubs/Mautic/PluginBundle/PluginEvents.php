<?php

declare(strict_types=1);

namespace Mautic\PluginBundle;

final class PluginEvents
{
    public const PLUGIN_ON_INTEGRATION_CONFIG_SAVE = 'mautic.plugin_on_integration_config_save';
    public const ON_PLUGIN_UPDATE = 'mautic.plugin.on_plugin_update';
    public const ON_PLUGIN_INSTALL = 'mautic.plugin.on_plugin_install';
}
