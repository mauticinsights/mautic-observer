<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Controller;

use MauticPlugin\MauticInsightsBundle\Service\InsightsApiConnectionTester;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class HealthPanelController
{
    public function __construct(
        private Environment $twig,
        private InsightsApiConnectionTester $connectionTester,
    ) {
    }

    public function fragmentAction(): Response
    {
        return new Response(
            $this->twig->render('@MauticInsightsBundle/Integration/Config/_health_panel.html.twig')
        );
    }

    public function testNowAction(): Response
    {
        $this->connectionTester->test();

        return new Response(
            $this->twig->render('@MauticInsightsBundle/Integration/Config/_health_panel.html.twig')
        );
    }
}
