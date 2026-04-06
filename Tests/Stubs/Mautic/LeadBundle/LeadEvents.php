<?php

declare(strict_types=1);

namespace Mautic\LeadBundle;

final class LeadEvents
{
    public const LEAD_POST_SAVE = 'mautic.lead_post_save';

    public const LEAD_PRE_DELETE = 'mautic.lead_pre_delete';

    public const LEAD_POST_DELETE = 'mautic.lead_post_delete';

    public const LEAD_POINTS_CHANGE = 'mautic.lead_points_change';

    public const LEAD_LIST_CHANGE = 'mautic.lead_list_change';

    public const LEAD_IDENTIFIED = 'mautic.lead_identified';

    public const LIST_POST_SAVE = 'mautic.lead_list_post_save';

    public const LIST_POST_DELETE = 'mautic.lead_list_post_delete';
}
