<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\Service;

use Mautic\CoreBundle\Helper\AppVersion;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\InsightsPluginVersion;
use MauticPlugin\MauticInsightsBundle\Service\InsightsApiConnectionTester;
use MauticPlugin\MauticInsightsBundle\Service\InsightsHttpTokenResolver;
use MauticPlugin\MauticInsightsBundle\Service\InsightsLastApiTelemetry;
use MauticPlugin\MauticInsightsBundle\Service\InsightsPublishedIntegrationFinder;
use MauticPlugin\MauticInsightsBundle\Service\MauticLeadCountsForInsightsStatus;
use MauticPlugin\MauticInsightsBundle\Service\StatusNotifyPayload;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class InsightsApiConnectionTesterTest extends TestCase
{
    public function testTestPostsStatusPayloadWithPluginEnabledTrue(): void
    {
        $captured = [];
        $integration = new Integration(true, [], InsightsApiConfig::NAME, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => 'conn-token',
        ]);

        $httpClient = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$captured): MockResponse {
                $captured[] = ['method' => $method, 'url' => $url, 'options' => $options];

                return new MockResponse('{}', ['http_code' => 204]);
            }
        );

        $leadCounts = $this->createMock(MauticLeadCountsForInsightsStatus::class);
        $leadCounts->method('countIdentified')->willReturn(11);
        $leadCounts->method('countAnonymous')->willReturn(4);

        $coreParams = new CoreParametersHelper();
        $coreParams->params = ['mailer_from_email' => 'tester@example.test'];

        $tester = new InsightsApiConnectionTester(
            InsightsPublishedIntegrationFinder::createForTest($integration),
            new InsightsHttpTokenResolver(),
            $httpClient,
            new InsightsLastApiTelemetry(sys_get_temp_dir(), new NullLogger()),
            new AppVersion('5.2.0'),
            $leadCounts,
            $coreParams,
        );

        $result = $tester->test();

        $this->assertTrue($result['ok']);
        $this->assertSame(204, $result['http_status']);
        $this->assertCount(1, $captured);

        $body = json_decode($captured[0]['options']['body'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            StatusNotifyPayload::build('5.2.0', InsightsPluginVersion::get(), 11, 4, 'tester@example.test', true),
            $body,
        );
        $this->assertTrue($body['plugin-enabled']);
    }
}
