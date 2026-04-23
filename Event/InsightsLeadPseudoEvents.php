<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Event;

/**
 * Outbound {@code event} strings emitted by the Insights plugin when Mautic fires
 * {@see \Mautic\LeadBundle\LeadEvents::LEAD_POST_SAVE} with tag or stage mutations on the entity
 * change log. These keys are not part of core {@see \Mautic\LeadBundle\LeadEvents}.
 *
 * Mirror: {@see \App\Event\Mautic\LeadEvents} on the Symfony app.
 */
final class InsightsLeadPseudoEvents
{
    public const LEAD_TAGS_CHANGE = 'mautic.lead_tags_change';

    public const LEAD_STAGE_CHANGE = 'mautic.lead_stage_change';

    private function __construct()
    {
    }
}
