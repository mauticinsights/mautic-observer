<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use IntlDateFormatter;
use Locale;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CoreBundle\Helper\AppVersion;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\EmailBundle\Entity\Email;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use MauticPlugin\MauticInsightsBundle\Buffer\ChainPersistentEventBuffer;
use MauticPlugin\MauticInsightsBundle\Buffer\PersistentBufferFeatureReader;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\InsightsPluginVersion;
use Symfony\Contracts\Translation\LocaleAwareInterface;
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
        private ?LocaleAwareInterface $localeAware = null,
        private ?UserHelper $userHelper = null,
    ) {
    }

    /**
     * `counts.contacts` counts identified leads only (dateIdentified IS NOT NULL), same as {@see InsightsApiConfig::STATUS_NOTIFY_URL} payload.
     * `counts.emails` is the number of {@see Email} entities (Mautic email library).
     *
     * @return array{
     *     mautic_version: string,
     *     plugin_version: string,
     *     last_event: array{present: bool, ok: ?bool, at: string, at_display: string, url: string, method: string, http_status: int, error: ?string},
     *     last_status: array{present: bool, ok: ?bool, at: string, at_display: string, url: string, method: string, http_status: int, error: ?string},
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
            'last_event' => $this->buildApiHealthRow($this->lastApiTelemetry->getLastEvent()),
            'last_status' => $this->buildApiHealthRow($this->lastApiTelemetry->getLastStatus()),
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
     * @param array{ok: bool, at: string, url: string, method: string, http_status: int, error: ?string}|null $row
     *
     * @return array{present: bool, ok: ?bool, at: string, at_display: string, url: string, method: string, http_status: int, error: ?string}
     */
    private function buildApiHealthRow(?array $row): array
    {
        if (null === $row) {
            return [
                'present' => false,
                'ok' => null,
                'at' => '',
                'at_display' => '',
                'url' => '',
                'method' => '',
                'http_status' => 0,
                'error' => null,
            ];
        }

        $at = $row['at'];

        return [
            'present' => true,
            'ok' => $row['ok'],
            'at' => $at,
            'at_display' => $this->formatTelemetryInstantForDisplay($at),
            'url' => $row['url'],
            'method' => $row['method'],
            'http_status' => $row['http_status'],
            'error' => $row['error'],
        ];
    }

    private function formatTelemetryInstantForDisplay(string $at): string
    {
        $trim = trim($at);
        if ('' === $trim) {
            return '';
        }

        try {
            $dt = new DateTimeImmutable($at);
        } catch (Throwable) {
            return $at;
        }

        $userTz = $this->resolveDisplayTimezone();
        $tzObj = new DateTimeZone($userTz);
        $local = $dt->setTimezone($tzObj);

        if (\extension_loaded('intl')) {
            try {
                $locale = $this->localeAware?->getLocale() ?? Locale::getDefault();
                if ('' === trim($locale)) {
                    $locale = 'en';
                }
                $formatter = new IntlDateFormatter(
                    $locale,
                    IntlDateFormatter::MEDIUM,
                    IntlDateFormatter::SHORT,
                    $userTz,
                    IntlDateFormatter::GREGORIAN
                );
                $formatted = $formatter->format($local);
                if (\is_string($formatted) && '' !== $formatted) {
                    return $formatted;
                }
            } catch (Throwable) {
            }
        }

        $suffix = 'UTC' === $userTz ? ' UTC' : ' '.$local->format('T');

        return $local->format('Y-m-d H:i').$suffix;
    }

    private function resolveDisplayTimezone(): string
    {
        try {
            $tz = (string) ($this->userHelper?->getUser()?->getTimezone() ?? '');
            if ('' !== trim($tz)) {
                new DateTimeZone($tz);

                return $tz;
            }
        } catch (Throwable) {
        }

        return 'UTC';
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
