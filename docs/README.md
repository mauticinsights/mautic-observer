# Mautic Insights observer plugin — documentation

Integration notes for the **`mautic-observer`** bundle (`MauticPlugin\MauticInsightsBundle`): what is sent to the MauticInsights ingest API and how payloads are shaped.

| Document | Description |
|----------|-------------|
| [Outbound events](outbound-events.md) | Email and campaign subscribers, buffering, payload shapes, removed hooks, `graph_events` serialization, step-level funnel fields |

**MauticInsights app** (ingest handler, `mautic_campaign_graph_events`, `/event` persistence): [`../../mauticinsights/docs/ingest-campaigns-graph-funnel.md`](../../mauticinsights/docs/ingest-campaigns-graph-funnel.md).

**Lead ingest** (batching, skip rules, lead-only scope): [`../../mauticinsights/docs/lead-events.md`](../../mauticinsights/docs/lead-events.md).
