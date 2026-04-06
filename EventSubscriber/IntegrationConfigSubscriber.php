<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\ConfigBundle\ConfigEvents;
use Mautic\ConfigBundle\Event\ConfigEvent;
use Mautic\CoreBundle\Helper\AppVersion;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\IntegrationsBundle\Event\ConfigSaveEvent;
use Mautic\IntegrationsBundle\IntegrationEvents;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Event\PluginIntegrationEvent;
use Mautic\PluginBundle\PluginEvents;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Service\InsightsHttpTokenResolver;
use MauticPlugin\MauticInsightsBundle\Service\InsightsLastApiTelemetry;
use MauticPlugin\MauticInsightsBundle\Service\InsightsPublishedIntegrationFinder;
use MauticPlugin\MauticInsightsBundle\Service\StatusNotifyPayload;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Notifies /api/status after integration settings save (IntegrationsBundle and PluginBundle UI paths), or when global
 * Mautic configuration is about to be saved ({@see ConfigEvents::CONFIG_PRE_SAVE}), if the integration is published
 * and a token is set. HTTP runs on terminate.
 */
class IntegrationConfigSubscriber implements EventSubscriberInterface
{
    /** @var array{token: string, version: string, senderEmail?: string}|null */
    private ?array $pendingStatusNotify = null;

    public function __construct(
        private HttpClientInterface $httpClient,
        private AppVersion $appVersion,
        private CoreParametersHelper $coreParametersHelper,
        private EntityManagerInterface $entityManager,
        private InsightsPublishedIntegrationFinder $integrationFinder,
        private InsightsHttpTokenResolver $httpTokenResolver,
        private LoggerInterface $logger,
        private InsightsLastApiTelemetry $lastApiTelemetry,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            IntegrationEvents::INTEGRATION_CONFIG_AFTER_SAVE => ['queueStatusNotify', 0],
            PluginEvents::PLUGIN_ON_INTEGRATION_CONFIG_SAVE => ['onPluginIntegrationConfigSave', 0],
            ConfigEvents::CONFIG_PRE_SAVE => ['onGlobalConfigPreSave', 0],
            KernelEvents::TERMINATE => ['sendQueuedStatusNotify', -1024],
        ];
    }

    public function queueStatusNotify(ConfigSaveEvent $event): void
    {
        $this->queueStatusNotifyIfInsightsIntegration($event->getIntegrationConfiguration());
    }

    public function onPluginIntegrationConfigSave(PluginIntegrationEvent $event): void
    {
        $this->queueStatusNotifyIfInsightsIntegration($event->getEntity());
    }

    public function onGlobalConfigPreSave(ConfigEvent $event): void
    {
        $integration = $this->integrationFinder->findPublished();
        if (null === $integration) {
            return;
        }

        $emailConfig = $event->getConfig('emailconfig');
        $senderFromForm = null;
        if (\is_array($emailConfig) && \array_key_exists('mailer_from_email', $emailConfig)) {
            $senderFromForm = trim((string) $emailConfig['mailer_from_email']);
        }

        $this->queueStatusNotifyForIntegration($integration, $senderFromForm);
    }

    public function sendQueuedStatusNotify(TerminateEvent $event): void
    {
        if (!$event->isMainRequest() || null === $this->pendingStatusNotify) {
            return;
        }

        $pending = $this->pendingStatusNotify;
        $this->pendingStatusNotify = null;

        try {
            $contactCount = $this->countIdentifiedContacts();
            $senderEmail = (string) ($pending['senderEmail'] ?? $this->coreParametersHelper->get('mailer_from_email'));

            $response = $this->httpClient->request('POST', InsightsApiConfig::STATUS_NOTIFY_URL, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer '.$pending['token'],
                ],
                'json' => StatusNotifyPayload::build($pending['version'], $contactCount, $senderEmail),
                'timeout' => 15,
            ]);

            $status = $response->getStatusCode();
            $ok = $status >= 200 && $status < 300;
            if (!$ok) {
                $this->logger->warning(
                    sprintf('Mautic Insights: status endpoint returned HTTP %d', $status),
                    ['body' => $response->getContent(false)]
                );
                $this->lastApiTelemetry->record(
                    InsightsApiConfig::STATUS_NOTIFY_URL,
                    'POST',
                    $status,
                    false,
                    $response->getContent(false)
                );
            } else {
                $this->lastApiTelemetry->record(InsightsApiConfig::STATUS_NOTIFY_URL, 'POST', $status, true);
            }
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Mautic Insights: POST %s failed: %s', InsightsApiConfig::STATUS_NOTIFY_URL, $e->getMessage()),
                ['exception' => $e]
            );
            $this->lastApiTelemetry->record(InsightsApiConfig::STATUS_NOTIFY_URL, 'POST', 0, false, $e->getMessage());
        }
    }

    private function queueStatusNotifyIfInsightsIntegration(Integration $integration): void
    {
        if (InsightsApiConfig::NAME !== $integration->getName()) {
            return;
        }

        $this->queueStatusNotifyForIntegration($integration, null);
    }

    /**
     * @param string|null $senderEmailOverride Value from config form key emailconfig.mailer_from_email; null = use CoreParametersHelper on send
     */
    private function queueStatusNotifyForIntegration(Integration $integration, ?string $senderEmailOverride): void
    {
        if (!$integration->getIsPublished()) {
            return;
        }

        $token = $this->httpTokenResolver->resolve($integration);

        if ('' === $token) {
            return;
        }

        $pending = [
            'token' => $token,
            'version' => (string) $this->appVersion->getVersion(),
        ];

        if (null !== $senderEmailOverride) {
            $pending['senderEmail'] = $senderEmailOverride;
        }

        $this->pendingStatusNotify = $pending;
    }

    /**
     * Same as Mautic contact list "non-anonymous" (identified): lead has date_identified set.
     *
     * @see \Mautic\LeadBundle\Entity\LeadRepository::addSearchCommandWhereClause() is:anonymous → date_identified IS NULL
     */
    private function countIdentifiedContacts(): int
    {
        try {
            $qb = $this->entityManager->createQueryBuilder();
            $qb->select('COUNT(l.id)')
                ->from(Lead::class, 'l')
                ->where($qb->expr()->isNotNull('l.dateIdentified'));

            return (int) $qb->getQuery()->getSingleScalarResult();
        } catch (Throwable $e) {
            $this->logger->warning(
                'Mautic Insights: could not count identified contacts: '.$e->getMessage(),
                ['exception' => $e]
            );

            return 0;
        }
    }
}
