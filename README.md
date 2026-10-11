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

Install from the root of your Mautic project — no custom `repositories` entry is required. Use a constraint that matches your Mautic major version.

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

### Alternative: install from ZIP (no Composer)

1. Download **Source code (zip)** for the release that matches your Mautic major version (plugin `5.x` → Mautic 5.x, `6.x` → Mautic 6.x, `7.x` → Mautic 7.x, …): [github.com/mauticinsights/mautic-observer/releases](https://github.com/mauticinsights/mautic-observer/releases).
2. Extract the ZIP into your Mautic plugins directory as **`plugins/MauticInsightsBundle`** (on Composer-based installs often `docroot/plugins/MauticInsightsBundle`).

   The plugin root must contain `composer.json` at:

   `plugins/MauticInsightsBundle/composer.json`

   GitHub ZIPs usually unpack to a folder like `mautic-observer-7.0.4` — rename that folder to `MauticInsightsBundle` (or extract so the contents land directly in `plugins/MauticInsightsBundle/`).
3. In the Mautic UI, open **Settings → Plugins** and click **Install/Upgrade Plugins** (instead of `composer require`).

### After install

If you have CLI access:

```bash
php bin/console cache:clear
php bin/console mautic:plugins:reload
```

Then in the Mautic UI:

1. Open **Settings → Plugins** (after a ZIP install, use **Install/Upgrade Plugins** first if the plugin is not listed yet).
2. Find **Mautic Insights**
3. Enter the Insights API token and required settings
4. Publish / enable the integration

### Alternative: install from GitHub (VCS)

Only needed if you want a branch or unreleased commit. Add to the `repositories` section of Mautic’s `composer.json`:

```json
{
  "type": "vcs",
  "url": "https://github.com/mauticinsights/mautic-observer"
}
```

Then use the development constraints below.

### Optional: development branches

```bash
# Mautic 7
composer require mauticinsights/mautic-observer:dev-main -W

# Mautic 6
composer require mauticinsights/mautic-observer:dev-6.x -W

# Mautic 5
composer require mauticinsights/mautic-observer:dev-5.x -W
```

## Local symlink into Mautic

Use [`link.sh`](./link.sh) from this repo to symlink into a local Mautic `plugins/` directory.

**Important:** do not run `composer install` inside the linked plugin directory while Mautic is using it — a nested `vendor/` will break Mautic’s autoloader (`ClassLoader` already in use). Run plugin unit tests from a separate checkout or remove `vendor/` before starting Mautic.

## Verify it works

- Open the plugin Health panel after saving configuration — no errors
- Confirm events flush without warnings in Mautic logs
- Check API endpoint reachability and write access to the Insights buffer directory (`var/mauticinsights-buffer` under the Mautic project)

## License

Proprietary — see [LICENSE](./LICENSE).
