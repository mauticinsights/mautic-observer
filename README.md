# Mautic Insights Observer

Observes Mautic contact, email, campaign, form, page and segment events and streams them to the [Mautic Insights](https://packagist.org/packages/mauticinsights/mautic-observer) ingest API.

Package: [`mauticinsights/mautic-observer`](https://packagist.org/packages/mauticinsights/mautic-observer) on Packagist.

**This branch (`7.x` / `main`) targets Mautic 7.** Use branch `6.x` / package `^6.0` for Mautic 6, or `5.x` / `^5.0` for Mautic 5.

## What this plugin does

- Listens to key Mautic events (contacts, email, campaigns, forms, page hits, segments)
- Buffers and sends outbound payloads to the Insights API (`/event`, `/status`)
- Supports bulk sync on enable, segment reconcile after batch rebuilds, and a Health panel in the plugin UI

## Requirements

| Line | PHP | Mautic | Packagist constraint |
|------|-----|--------|----------------------|
| Mautic 5 | PHP 8.0+ | Mautic 5.x | `mauticinsights/mautic-observer:^5.0` |
| Mautic 6 | PHP 8.1+ | Mautic 6.x (`mautic/core-lib` ^6.0) | `mauticinsights/mautic-observer:^6.0` |
| Mautic 7 | PHP 8.2+ | Mautic 7.x (`mautic/core-lib` ^7.0) | `mauticinsights/mautic-observer:^7.0` |

- Composer 2.x

## Installation (Packagist)

Install from the root of your Mautic project — no custom `repositories` entry is required.

### Mautic 7

```bash
composer require mauticinsights/mautic-observer:^7.0
```

### Mautic 6

```bash
composer require mauticinsights/mautic-observer:^6.0
```

### Mautic 5

```bash
composer require mauticinsights/mautic-observer:^5.0
```

Composer installs the bundle into `plugins/MauticInsightsBundle` (`extra.install-directory-name`).

### After install

```bash
php bin/console cache:clear
php bin/console mautic:plugins:reload
```

Then in the Mautic UI:

1. Open **Settings → Plugins**
2. Find **Mautic Insights**
3. Enter the Insights API token and required settings
4. Publish / enable the integration

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
