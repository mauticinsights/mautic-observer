<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\AppVersion;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\InsightsPluginVersion;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Fires an on-demand POST /status to verify API connectivity and records the telemetry result.
 */
final class InsightsApiConnectionTester
{
    public function __construct(
        private InsightsPublishedIntegrationFinder $integrationFinder,
        private InsightsHttpTokenResolver $httpTokenResolver,
        private HttpClientInterface $httpClient,
        private InsightsLastApiTelemetry $lastApiTelemetry,
        private AppVersion $appVersion,
        private EntityManagerInterface $entityManager,
        private CoreParametersHelper $coreParametersHelper,
    ) {
    }

    /**
     * @return array{ok: bool, http_status: int, error: ?string}
     */
    public function test(): array
    {
        $integration = $this->integrationFinder->findPublished();
        if (null === $integration) {
            return ['ok' => false, 'http_status' => 0, 'error' => 'integration_not_published'];
        }

        $token = $this->httpTokenResolver->resolve($integration);
        if ('' === $token) {
            return ['ok' => false, 'http_status' => 0, 'error' => 'no_token'];
        }

        try {
            $response = $this->httpClient->request('POST', InsightsApiConfig::STATUS_NOTIFY_URL, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer '.$token,
                ],
                'json' => StatusNotifyPayload::build(
                    (string) $this->appVersion->getVersion(),
                    InsightsPluginVersion::get(),
                    $this->countIdentifiedContacts(),
                    (string) $this->coreParametersHelper->get('mailer_from_email'),
                ),
                'timeout' => 15,
            ]);

            $status = $response->getStatusCode();
            $ok = $status >= 200 && $status < 300;
            $this->lastApiTelemetry->record(
                InsightsApiConfig::STATUS_NOTIFY_URL,
                'POST',
                $status,
                $ok,
                $ok ? null : 'HTTP '.$status,
            );

            return ['ok' => $ok, 'http_status' => $status, 'error' => $ok ? null : 'HTTP '.$status];
        } catch (Throwable $e) {
            $this->lastApiTelemetry->record(InsightsApiConfig::STATUS_NOTIFY_URL, 'POST', 0, false, $e->getMessage());

            return ['ok' => false, 'http_status' => 0, 'error' => $e->getMessage()];
        }
    }

    private function countIdentifiedContacts(): int
    {
        try {
            $qb = $this->entityManager->createQueryBuilder();
            $qb->select('COUNT(l.id)')
                ->from(Lead::class, 'l')
                ->where($qb->expr()->isNotNull('l.dateIdentified'));

            return (int) $qb->getQuery()->getSingleScalarResult();
        } catch (Throwable) {
            return 0;
        }
    }
}
