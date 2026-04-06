<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CoreBundle\Helper\AppVersion;
use Mautic\EmailBundle\Entity\Email;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use MauticPlugin\MauticInsightsBundle\InsightsPluginVersion;
use Throwable;

/**
 * Read-only snapshot for the integration config Health tab.
 */
final class InsightsPluginHealthProvider
{
    public function __construct(
        private AppVersion $appVersion,
        private EntityManagerInterface $entityManager,
        private PersistentBufferFeatureReader $featureReader,
        private ChainPersistentEventBuffer $chainPersistentEventBuffer,
        private InsightsLastApiTelemetry $lastApiTelemetry,
    ) {
    }

    /**
     * `counts.contacts` counts identified leads only (dateIdentified IS NOT NULL), same as `/api/status`.
     * `counts.emails` is the number of {@see Email} entities (Mautic email library).
     *
     * @return array{
     *     mautic_version: string,
     *     plugin_version: string,
     *     last_api: array{present: bool, ok: ?bool, at: string, url: string, method: string, http_status: int, error: ?string},
     *     buffering: array{durable_enabled: bool, backend_key: ?string},
     *     counts: array{contacts: int, emails: int, segments: int, campaigns: int}
     * }
     */
    public function getSnapshot(): array
    {
        $durable = $this->featureReader->isDurableBufferEnabled();
        $backendKey = $durable ? $this->chainPersistentEventBuffer->getActiveBackendId() : null;

        return [
            'mautic_version' => (string) $this->appVersion->getVersion(),
            'plugin_version' => InsightsPluginVersion::get(),
            'last_api' => $this->buildLastApi(),
            'buffering' => [
                'durable_enabled' => $durable,
                'backend_key' => $backendKey,
            ],
            'counts' => [
                'contacts' => $this->safeCountIdentifiedContacts(),
                'emails' => $this->safeCount(Email::class),
                'segments' => $this->safeCount(LeadList::class),
                'campaigns' => $this->safeCountCampaigns(),
            ],
        ];
    }

    /**
     * @return array{present: bool, ok: ?bool, at: string, url: string, method: string, http_status: int, error: ?string}
     */
    private function buildLastApi(): array
    {
        $row = $this->lastApiTelemetry->getLast();
        if (null === $row) {
            return [
                'present' => false,
                'ok' => null,
                'at' => '',
                'url' => '',
                'method' => '',
                'http_status' => 0,
                'error' => null,
            ];
        }

        return [
            'present' => true,
            'ok' => $row['ok'],
            'at' => $row['at'],
            'url' => $row['url'],
            'method' => $row['method'],
            'http_status' => $row['http_status'],
            'error' => $row['error'],
        ];
    }

    /**
     * Same as Mautic non-anonymous contacts: Lead has dateIdentified set.
     */
    private function safeCountIdentifiedContacts(): int
    {
        try {
            $qb = $this->entityManager->createQueryBuilder();
            $qb->select('COUNT(l.id)')
                ->from(Lead::class, 'l')
                ->where($qb->expr()->isNotNull('l.dateIdentified'));

            return (int) $qb->getQuery()->getSingleScalarResult();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function safeCount(string $entityClass): int
    {
        try {
            $qb = $this->entityManager->createQueryBuilder();
            $alias = 'e';
            $qb->select('COUNT('.$alias.'.id)')
                ->from($entityClass, $alias);

            return (int) $qb->getQuery()->getSingleScalarResult();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function safeCountCampaigns(): int
    {
        try {
            $qb = $this->entityManager->createQueryBuilder();
            $qb->select('COUNT(c.id)')
                ->from(Campaign::class, 'c')
                ->where('c.deleted IS NULL');

            return (int) $qb->getQuery()->getSingleScalarResult();
        } catch (Throwable $e) {
            return 0;
        }
    }
}
