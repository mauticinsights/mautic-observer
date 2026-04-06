<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Twig;

use MauticPlugin\MauticInsightsBundle\Service\InsightsPluginHealthProvider;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class InsightsIntegrationHealthExtension extends AbstractExtension
{
    public function __construct(
        private InsightsPluginHealthProvider $healthProvider,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('mautic_insights_health', [$this->healthProvider, 'getSnapshot']),
        ];
    }
}
