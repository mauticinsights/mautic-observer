# Mautic Insights observer plugin — outbound events

The plugin lives in **`mautic-observer/`** (Symfony bundle `MauticPlugin\MauticInsightsBundle`). It batches JSON to the MauticInsights ingest API (`POST …/event`, body `{ "events": [ … ] }`) after the Mautic request, via `DeferredOutboundEventQueue` + `MauticEventBuffer` (see bundle code and [`../../mauticinsights/docs/lead-events.md`](../../mauticinsights/docs/lead-events.md) for the general pipeline).

---

## Email events (`EmailInsightsEventSubscriber`)

### Currently subscribed and sent

The plugin forwards these Mautic `EmailEvents` hooks when an outbound token is configured:

| Mautic constant | Purpose |
|-----------------|--------|
| `EMAIL_ON_OPEN` | Opens |
| `EMAIL_ON_SEND` | Sends |
| `EMAIL_ON_REPLY` | Replies (same payload shape as open) |
| `EMAIL_FAILED` | Queue failure (`QueueEmailEvent`) |
| `EMAIL_POST_SAVE` / `EMAIL_POST_DELETE` | Email entity sync (dimension in the app) |

### Removed (no longer subscribed)

The following hooks are **not** forwarded anymore (reduced noise; send/open metrics were unaffected for display; queue resend was duplicate-shaped vs failed):

- `EMAIL_ON_DISPLAY`
- `EMAIL_RESEND`
- `ON_SENT_EMAIL_TO_USER`
- `ON_TRANSPORT_WEBHOOK`

The Symfony app mirrors this in `App\Event\Mautic\EmailEvents` (constants removed).

### Payload notes

- **Send** uses `EmailInsightsContextBuilder::buildEmailSendPayload`; internal sends or missing lead are skipped (`null` payload).
- **Failed queue** events carry mostly `properties` from the message (subject, addresses, `lead_id_hash` when present); they often lack top-level `email_id` / `contact_id`.
- **Post save/delete** use top-level `email_id` plus `properties` (`name`, `operation`, optional `subject`, `email_type`, `is_published`, first segment id as `segment_id`).

---

## Campaign events (`CampaignAnalyticsEventSubscriber`)

### Mautic hooks vs outbound `event` strings

The subscriber listens to **`CampaignBundle\CampaignEvents`**, but **runtime funnel rows** do not always keep the `mautic.campaign_*` name:

| Mautic event | Outbound JSON `event` value |
|--------------|-----------------------------|
| `CAMPAIGN_POST_SAVE` | `mautic.campaign_post_save` |
| `CAMPAIGN_POST_DELETE` | `mautic.campaign_post_delete` |
| `CAMPAIGN_ON_LEADCHANGE` | **`campaign.added`** or **`campaign.removed`** (one row per contact) |
| `ON_EVENT_EXECUTED` / `ON_EVENT_EXECUTED_BATCH` | **`campaign.step_executed`** |
| `ON_EVENT_FAILED` | **`campaign.step_failed`** |
| `ON_EVENT_SCHEDULED` | **`campaign.step_scheduled`** |

Post save/delete use the **deferred queue** (same flush batch as leads/emails). Lead-change and step hooks use **`MauticEventBuffer::push()`** directly.

### Campaign save payload — graph for funnel UI

On **`CAMPAIGN_POST_SAVE`**, `properties` always includes **`graph_events`**: an array (possibly empty) of serialized Mautic campaign **workflow nodes** (`CampaignBundle\Entity\Event`), produced by **`CampaignGraphEventsSerializer`**.

Each element is a plain associative array (snake_case keys), for example:

- `campaign_event_id` — Mautic `campaign_events.id`
- `name`, optional `description`
- `step_type` — Mautic `type` (action / decision / condition)
- `event_type` — Mautic `event_type` (e.g. `email.send`)
- `step_order` — Mautic `order`
- `decision_path`, `parent_campaign_event_id` when applicable
- `channel`, `channel_id`, `trigger_mode` when set
- `properties` — Mautic node configuration (only included when non-empty)

Rows are sorted by **`step_order`** before sending.

#### Design notes

1. **Doctrine lazy loading** — `Campaign::getEvents()` is mapped `fetch="EXTRA_LAZY"` in Mautic. In normal post-save flows the collection is usually materialised; if you ever see empty `graph_events` for a non-empty builder, consider forcing initialization in the subscriber (explicit repository load) before serializing.
2. **`canvas_settings`** on the campaign entity are **not** included in `graph_events` by default (large, UI-oriented). Add them in the serializer if you need layout in MauticInsights.
3. **Empty graph** — the key is still sent as `graph_events: []` so the app can replace the stored graph with zero nodes.

### Step events — extended context (funnel analytics)

For **`campaign.step_*`** payloads, `properties` include funnel-oriented fields when available from `LeadEventLog` / the related `Event` entity:

- `event_id` — same as Mautic graph node id (`campaign_events.id`)
- `event_type` — from `Event::getEventType()`
- `lead_event_log_id` — log row id
- `rotation`
- `non_action_path_taken` (boolean, when applicable)
- `metadata` (non-empty arrays only)

Together with `step_type`, `step_name`, `contact_id`, `campaign_id`, and optional `ts`, this supports “which node”, successes, failures, and scheduled waits.

---

## Tests and quality

- Plugin: `mautic-observer` — PHPUnit + PHPStan (`make all` / `composer ci` in that directory; host PHP).
- App ingest tests run in Docker (`make test` under `mauticinsights/`).
