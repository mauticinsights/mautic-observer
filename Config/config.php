<?php

declare(strict_types=1);

return [
    'name' => 'Mautic Insights',
    'description' => 'Observes Mautic events and streams them to the Mautic Insights ingest API.',
    'version' => '7.0.1',
    'author' => 'Mautic Insights',
    'routes' => [
        'main' => [
            'mauticinsights_health_panel_fragment' => [
                'path' => '/plugins/mauticinsights/health-panel',
                'controller' => 'MauticPlugin\MauticInsightsBundle\Controller\HealthPanelController::fragmentAction',
            ],
            'mauticinsights_health_panel_test_now' => [
                'path' => '/plugins/mauticinsights/test-now',
                'controller' => 'MauticPlugin\MauticInsightsBundle\Controller\HealthPanelController::testNowAction',
                'method' => 'POST',
            ],
        ],
        'public' => [],
        'api' => [],
    ],
    'menu' => [],
    'services' => [
        'events' => [],
        'forms' => [],
        'helpers' => [],
        'other' => [],
        'models' => [],
        'integrations' => [],
        'fixtures' => [],
    ],
    'parameters' => [],
];
