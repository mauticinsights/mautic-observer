<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\EventSubscriber;

use Mautic\ConfigBundle\ConfigEvents;
use Mautic\ConfigBundle\Event\ConfigEvent;
use Mautic\CoreBundle\Helper\AppVersion;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\IntegrationsBundle\Event\ConfigSaveEvent;
use Mautic\IntegrationsBundle\IntegrationEvents;
use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Event\PluginInstallEvent;
use Mautic\PluginBundle\Event\PluginIntegrationEvent;
use Mautic\PluginBundle\Event\PluginUpdateEvent;
use Mautic\PluginBundle\PluginEvents;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\InsightsPluginVersion;
use MauticPlugin\MauticInsightsBundle\Service\InsightsApiSendGate;
use MauticPlugin\MauticInsightsBundle\Service\InsightsHttpTokenResolver;
use MauticPlugin\MauticInsightsBundle\Service\InsightsLastApiTelemetry;
use MauticPlugin\MauticInsightsBundle\Service\InsightsPublishedIntegrationFinder;
use MauticPlugin\MauticInsightsBundle\Service\MauticLeadCountsForInsightsStatus;
use MauticPlugin\MauticInsightsBundle\Service\NoopInsightsApiSendGate;
use MauticPlugin\MauticInsightsBundle\Service\StatusNotifyPayload;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Notifies {@see InsightsApiConfig::STATUS_NOTIFY_URL} after integration settings save (IntegrationsBundle and
 * PluginBundle UI paths), after {@code mautic:plugins:reload} (and install/update aliases) completes successfully,
 * after {@see PluginEvents::ON_PLUGIN_UPDATE} / {@see PluginEvents::ON_PLUGIN_INSTALL} (version bump / first install),
 * or when global Mautic configuration is about to be saved ({@see ConfigEvents::CONFIG_PRE_SAVE}), if the integration
 * is published and a token is set. HTTP runs on terminate (kernel.terminate for HTTP requests, console.terminate for CLI).
 *
 * Note: Mautic only dispatches ON_PLUGIN_UPDATE when the version string in the plugin {@code config.php} changes;
 * a plain {@code mautic:plugins:reload} without a version bump does not fire that event — the reload command hook covers that.
 */
class IntegrationConfigSubscriber implements EventSubscriberInterface
{
    /** @var list<string> */
    private const PLUGIN_RELOAD_CONSOLE_COMMANDS = [
        'mautic:plugins:reload',
        'mautic:plugins:install',
        'mautic:plugins:update',
    ];

    /** @var array{token: string, version: string, senderEmail?: string}|null */
    private ?array $pendingStatusNotify = null;

    private InsightsApiSendGate $insightsApiSendGate;

    public function __construct(
        private HttpClientInterface $httpClient,
        private AppVersion $appVersion,
        private CoreParametersHelper $coreParametersHelper,
        private MauticLeadCountsForInsightsStatus $leadCountsForStatus,
        private InsightsPublishedIntegrationFinder $integrationFinder,
        private InsightsHttpTokenResolver $httpTokenResolver,
        private LoggerInterface $logger,
        private InsightsLastApiTelemetry $lastApiTelemetry,
        ?InsightsApiSendGate $insightsApiSendGate = null,
    ) {
        $this->insightsApiSendGate = $insightsApiSendGate ?? new NoopInsightsApiSendGate();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            IntegrationEvents::INTEGRATION_CONFIG_AFTER_SAVE => ['queueStatusNotify', 0],
            PluginEvents::PLUGIN_ON_INTEGRATION_CONFIG_SAVE => ['onPluginIntegrationConfigSave', 0],
            PluginEvents::ON_PLUGIN_UPDATE => ['onPluginUpdate', 0],
            PluginEvents::ON_PLUGIN_INSTALL => ['onPluginInstall', 0],
            ConfigEvents::CONFIG_PRE_SAVE => ['onGlobalConfigPreSave', 0],
            KernelEvents::TERMINATE => ['onHttpTerminate', -1024],
            ConsoleEvents::TERMINATE => ['onConsoleTerminate', -1024],
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

    public function onPluginUpdate(PluginUpdateEvent $event): void
    {
        if (InsightsApiConfig::PLUGIN_BUNDLE !== $event->getPlugin()->getBundle()) {
            return;
        }

        $this->queueStatusNotifyFromFinder();
    }

    public function onPluginInstall(PluginInstallEvent $event): void
    {
        if (InsightsApiConfig::PLUGIN_BUNDLE !== $event->getPlugin()->getBundle()) {
            return;
        }

        $this->queueStatusNotifyFromFinder();
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

    public function onHttpTerminate(TerminateEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->doSendQueuedStatusNotify();
    }

    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        if (0 === $event->getExitCode()) {
            $command = $event->getCommand();
            if (null !== $command) {
                $names = array_merge([$command->getName()], $command->getAliases());
                if ([] !== array_intersect($names, self::PLUGIN_RELOAD_CONSOLE_COMMANDS)) {
                    $this->queueStatusNotifyFromFinder();
                }
            }
        }

        $this->doSendQueuedStatusNotify();
    }

    public function sendQueuedStatusNotify(TerminateEvent $event): void
    {
        // Kept for backwards-compatibility; real logic is in onHttpTerminate / onConsoleTerminate.
        if (!$event->isMainRequest()) {
            return;
        }

        $this->doSendQueuedStatusNotify();
    }

    private function doSendQueuedStatusNotify(): void
    {
        if (null === $this->pendingStatusNotify) {
            return;
        }

        $pending = $this->pendingStatusNotify;
        $this->pendingStatusNotify = null;

        try {
            $identified = $this->leadCountsForStatus->countIdentified();
            $anonymous = $this->leadCountsForStatus->countAnonymous();
            $senderEmail = (string) ($pending['senderEmail'] ?? $this->coreParametersHelper->get('mailer_from_email'));

            $response = $this->httpClient->request('POST', InsightsApiConfig::STATUS_NOTIFY_URL, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer '.$pending['token'],
                ],
                'json' => StatusNotifyPayload::build(
                    $pending['version'],
                    InsightsPluginVersion::get(),
                    $identified,
                    $anonymous,
                    $senderEmail
                ),
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

        $this->insightsApiSendGate->resetStateOnIntegrationConfigChange();
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

    private function queueStatusNotifyFromFinder(): void
    {
        $integration = $this->integrationFinder->findPublished();
        if (null === $integration) {
            return;
        }

        $this->queueStatusNotifyForIntegration($integration, null);
    }
}
