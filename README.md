# Mautic Insights Observer

Observes Mautic contact, email, campaign, form, page and segment events and streams them to the [Mautic Insights](https://packagist.org/packages/mauticinsights/mautic-observer) ingest API.

Package: [`mauticinsights/mautic-observer`](https://packagist.org/packages/mauticinsights/mautic-observer) on Packagist.

## What this plugin does

- Listens to key Mautic events (contacts, email, campaigns, forms, page hits, segments)
- Buffers and sends outbound payloads to the Insights API (`/event`, `/status`)
- Supports bulk sync on enable, segment reconcile after batch rebuilds, and a Health panel in the plugin UI

## Requirements

- PHP 8.0+
- Mautic 5.x
- Composer 2.x

## Installation (Packagist)

Install from the root of your Mautic project — no custom `repositories` entry is required:

```bash
composer require mauticinsights/mautic-observer
```

Pin to the Mautic 5 line (recommended):

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

### Optional: development branch

Only if you need unreleased code from Packagist’s `dev-main`:

```bash
composer require mauticinsights/mautic-observer:dev-main -W
```

## Verify it works

- Open the plugin Health panel after saving configuration — no errors
- Confirm events flush without warnings in Mautic logs
- Check API endpoint reachability and write access to the Insights buffer directory (`var/mauticinsights-buffer` under the Mautic project)

## License

Proprietary — see [LICENSE](./LICENSE).
