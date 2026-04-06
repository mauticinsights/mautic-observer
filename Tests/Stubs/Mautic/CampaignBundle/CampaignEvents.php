<?php

declare(strict_types=1);

namespace Mautic\CampaignBundle;

final class CampaignEvents
{
    public const CAMPAIGN_POST_SAVE = 'mautic.campaign_post_save';

    public const CAMPAIGN_POST_DELETE = 'mautic.campaign_post_delete';

    public const CAMPAIGN_ON_LEADCHANGE = 'mautic.campaign_on_leadchange';

    public const ON_EVENT_EXECUTED = 'mautic.campaign_on_event_executed';

    public const ON_EVENT_EXECUTED_BATCH = 'mautic.campaign_on_event_executed_batch';

    public const ON_EVENT_FAILED = 'mautic.campaign_on_event_failed';

    public const ON_EVENT_SCHEDULED = 'mautic.campaign_on_event_scheduled';
}
