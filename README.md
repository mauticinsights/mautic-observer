# Mautic Insights Observer

A plugin for Mautic 5.x that captures key Mautic events and sends them to the Mautic Insights API.

## What This Plugin Does

- sends events to the Mautic Insights

## Requirements

- PHP 8.0+
- Mautic 5.x
- Composer 2.x

## Installation

### 1) Add the plugin repository to Mautic `composer.json`

Add this entry to the `repositories` section:

```json
{
  "type": "vcs",
  "url": "https://github.com/mauticinsights/mautic-observer"
}
```

### 2) Install the plugin with Composer

Run this in the root of your Mautic project:

```bash
composer require mauticinsights/mautic-observer
```

If you are installing from a branch without a release tag:

```bash
composer require mauticinsights/mautic-observer:dev-main -W
```

### 3) Clear cache and generate assets

```bash
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
php bin/console mautic:assets:generate
```

### 4) Activate in Mautic

- open Plugins page in the Mautic UI
- find **Mautic Insights**
- fill in the API token and required fields
- publish/enable the integration

## Verify It Works

- after saving configuration, confirm there are no errors in the Health panel
- check that events are written/flushed without warnings in Mautic logs
- if needed, verify cache directory permissions and API endpoint reachability

## License

The license is available in [LICENSE](./LICENSE).
