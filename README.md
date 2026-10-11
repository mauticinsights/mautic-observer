# Mautic Insights Observer

Observes Mautic contact, email, campaign, form, page and segment events and streams them to the [Mautic Insights](https://mauticinsights.com/) ingest API.

## Requirements

| Line | PHP | Mautic | Packagist constraint |
|------|-----|--------|----------------------|
| Mautic 5 | PHP 8.0+ | Mautic 5.x | `mauticinsights/mautic-observer:^5` |
| Mautic 6 | PHP 8.1+ | Mautic 6.x (`mautic/core-lib` ^6.0) | `mauticinsights/mautic-observer:^6` |
| Mautic 7 | PHP 8.2+ | Mautic 7.x (`mautic/core-lib` ^7.0) | `mauticinsights/mautic-observer:^7` |

- Composer 2.x

## Installation

### Recommended (Packagist)

Install from the root of your Mautic project. Use a constraint that matches your Mautic major version.

#### Mautic 7

```bash
composer require mauticinsights/mautic-observer:^7
```

#### Mautic 6

```bash
composer require mauticinsights/mautic-observer:^6
```

#### Mautic 5

```bash
composer require mauticinsights/mautic-observer:^5
```

Composer installs the bundle into `plugins/MauticInsightsBundle` (`extra.install-directory-name`).

then

```bash
php bin/console cache:clear
php bin/console mautic:plugins:reload
```

### Alternative: install from ZIP (no Composer)

1. Download **Source code (zip)** for the release that matches your Mautic major version (plugin `5.x` → Mautic 5.x, `6.x` → Mautic 6.x, `7.x` → Mautic 7.x, …): [github.com/mauticinsights/mautic-observer/releases](https://github.com/mauticinsights/mautic-observer/releases).
2. Extract the ZIP into your Mautic plugins directory as **`plugins/MauticInsightsBundle`** (on Composer-based installs often `docroot/plugins/MauticInsightsBundle`).

   The plugin root must contain `composer.json` at:

   `plugins/MauticInsightsBundle/composer.json`

   GitHub ZIPs usually unpack to a folder like `mautic-observer-7.0.4` — rename that folder to `MauticInsightsBundle` (or extract so the contents land directly in `plugins/MauticInsightsBundle/`).
3. In the Mautic UI, open **Settings → Plugins** and click **Install/Upgrade Plugins** (instead of `composer require`).

Then in the Mautic UI:

1. Open **Settings → Plugins** (after a ZIP install, use **Install/Upgrade Plugins** first if the plugin is not listed yet).
2. Find **Mautic Insights**
3. Enter the Insights API token and required settings
4. Publish / enable the integration

## License

Proprietary — see [LICENSE](./LICENSE).
