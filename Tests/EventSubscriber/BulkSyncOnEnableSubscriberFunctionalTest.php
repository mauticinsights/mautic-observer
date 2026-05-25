<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\EventSubscriber;

use Mautic\IntegrationsBundle\Event\ConfigSaveEvent;
use Mautic\IntegrationsBundle\IntegrationEvents;
use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Entity\Plugin;
use Mautic\PluginBundle\Event\PluginInstallEvent;
use Mautic\PluginBundle\Event\PluginUpdateEvent;
use Mautic\PluginBundle\PluginEvents;
use MauticPlugin\MauticInsightsBundle\EventSubscriber\BulkSyncOnEnableSubscriber;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Service\BulkSyncService;
use MauticPlugin\MauticInsightsBundle\Service\InsightsHttpTokenResolver;
use MauticPlugin\MauticInsightsBundle\Service\InsightsPublishedIntegrationFinder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class BulkSyncOnEnableSubscriberFunctionalTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────
    // Subscribed events
    // ──────────────────────────────────────────────────────────────

    public function testRegistersExpectedEvents(): void
    {
        $map = BulkSyncOnEnableSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(IntegrationEvents::INTEGRATION_CONFIG_AFTER_SAVE, $map);
        $this->assertArrayHasKey(PluginEvents::PLUGIN_ON_INTEGRATION_CONFIG_SAVE, $map);
        $this->assertArrayHasKey(PluginEvents::ON_PLUGIN_INSTALL, $map);
        $this->assertArrayHasKey(PluginEvents::ON_PLUGIN_UPDATE, $map);
        $this->assertArrayHasKey(\Symfony\Component\HttpKernel\KernelEvents::TERMINATE, $map);
        $this->assertArrayHasKey(\Symfony\Component\Console\ConsoleEvents::TERMINATE, $map);
    }

    public function testTerminateListenerHasLowerPriorityThanIntegrationConfigSubscriber(): void
    {
        $map = BulkSyncOnEnableSubscriber::getSubscribedEvents();

        $terminateEntry = $map[\Symfony\Component\HttpKernel\KernelEvents::TERMINATE];
        $priority       = is_array($terminateEntry) ? (int) ($terminateEntry[1] ?? 0) : 0;

        // IntegrationConfigSubscriber uses -1024; BulkSync must run after it.
        $this->assertLessThanOrEqual(-1025, $priority);
    }

    // ──────────────────────────────────────────────────────────────
    // INTEGRATION_CONFIG_AFTER_SAVE trigger path
    // ──────────────────────────────────────────────────────────────

    public function testSyncRunsOnTerminateWhenPublishedIntegrationSaved(): void
    {
        $syncService = $this->mockSyncService(expectedCalls: 1);
        $integration = $this->integration(published: true, token: 'valid-token');
        $subscriber  = $this->makeSubscriber($syncService, $integration);

        $subscriber->onConfigSave(new ConfigSaveEvent($integration));
        $subscriber->onHttpTerminate($this->makeTerminateEvent());
    }

    public function testSyncDoesNotRunWhenIntegrationIsUnpublished(): void
    {
        $syncService = $this->mockSyncService(expectedCalls: 0);
        $integration = $this->integration(published: false, token: 'valid-token');
        $subscriber  = $this->makeSubscriber($syncService, $integration);

        $subscriber->onConfigSave(new ConfigSaveEvent($integration));
        $subscriber->onHttpTerminate($this->makeTerminateEvent());
    }

    public function testSyncDoesNotRunWhenTokenIsEmpty(): void
    {
        $syncService = $this->mockSyncService(expectedCalls: 0);
        $integration = $this->integration(published: true, token: '');
        $subscriber  = $this->makeSubscriber($syncService, $integration);

        $subscriber->onConfigSave(new ConfigSaveEvent($integration));
        $subscriber->onHttpTerminate($this->makeTerminateEvent());
    }

    public function testSyncDoesNotRunForDifferentPlugin(): void
    {
        $syncService = $this->mockSyncService(expectedCalls: 0);
        $integration = new Integration(true, [], 'SomeOtherPlugin', ['http_token' => 'tok']);
        $subscriber  = $this->makeSubscriberWithFinder(
            $syncService,
            InsightsPublishedIntegrationFinder::createForTest($this->integration(published: true, token: 'tok')),
        );

        $subscriber->onConfigSave(new ConfigSaveEvent($integration));
        $subscriber->onHttpTerminate($this->makeTerminateEvent());
    }

    // ──────────────────────────────────────────────────────────────
    // ON_PLUGIN_INSTALL trigger path
    // ──────────────────────────────────────────────────────────────

    public function testSyncRunsAfterPluginInstallWhenPublished(): void
    {
        $syncService = $this->mockSyncService(expectedCalls: 1);
        $integration = $this->integration(published: true, token: 'tok');
        $subscriber  = $this->makeSubscriber($syncService, $integration);

        $subscriber->onPluginInstall($this->makeInstallEvent(InsightsApiConfig::PLUGIN_BUNDLE));
        $subscriber->onHttpTerminate($this->makeTerminateEvent());
    }

    public function testSyncDoesNotRunAfterInstallOfDifferentPlugin(): void
    {
        $syncService = $this->mockSyncService(expectedCalls: 0);
        $integration = $this->integration(published: true, token: 'tok');
        $subscriber  = $this->makeSubscriber($syncService, $integration);

        $subscriber->onPluginInstall($this->makeInstallEvent('SomethingElseBundle'));
        $subscriber->onHttpTerminate($this->makeTerminateEvent());
    }

    public function testSyncDoesNotRunAfterInstallWhenIntegrationUnpublished(): void
    {
        $syncService = $this->mockSyncService(expectedCalls: 0);
        $integration = $this->integration(published: false, token: 'tok');
        $subscriber  = $this->makeSubscriber($syncService, $integration);

        $subscriber->onPluginInstall($this->makeInstallEvent(InsightsApiConfig::PLUGIN_BUNDLE));
        $subscriber->onHttpTerminate($this->makeTerminateEvent());
    }

    // ──────────────────────────────────────────────────────────────
    // ON_PLUGIN_UPDATE trigger path
    // ──────────────────────────────────────────────────────────────

    public function testSyncRunsAfterPluginUpdateWhenPublished(): void
    {
        $syncService = $this->mockSyncService(expectedCalls: 1);
        $integration = $this->integration(published: true, token: 'tok');
        $subscriber  = $this->makeSubscriber($syncService, $integration);

        $subscriber->onPluginUpdate($this->makeUpdateEvent(InsightsApiConfig::PLUGIN_BUNDLE));
        $subscriber->onHttpTerminate($this->makeTerminateEvent());
    }

    public function testSyncDoesNotRunAfterUpdateOfDifferentPlugin(): void
    {
        $syncService = $this->mockSyncService(expectedCalls: 0);
        $integration = $this->integration(published: true, token: 'tok');
        $subscriber  = $this->makeSubscriber($syncService, $integration);

        $subscriber->onPluginUpdate($this->makeUpdateEvent('SomethingElseBundle'));
        $subscriber->onHttpTerminate($this->makeTerminateEvent());
    }

    // ──────────────────────────────────────────────────────────────
    // console.terminate path
    // ──────────────────────────────────────────────────────────────

    public function testSyncRunsOnConsoleTerminate(): void
    {
        $syncService = $this->mockSyncService(expectedCalls: 1);
        $integration = $this->integration(published: true, token: 'tok');
        $subscriber  = $this->makeSubscriber($syncService, $integration);

        $subscriber->onConfigSave(new ConfigSaveEvent($integration));
        $subscriber->onConsoleTerminate($this->makeConsoleTerminateEvent());
    }

    // ──────────────────────────────────────────────────────────────
    // Idempotency — sync queued only once even if multiple events fire
    // ──────────────────────────────────────────────────────────────

    public function testSyncRunsOnlyOnceEvenIfQueuedMultipleTimes(): void
    {
        $syncService = $this->mockSyncService(expectedCalls: 1);
        $integration = $this->integration(published: true, token: 'tok');
        $subscriber  = $this->makeSubscriber($syncService, $integration);

        // Two saves before terminate — sync should still run exactly once.
        $subscriber->onConfigSave(new ConfigSaveEvent($integration));
        $subscriber->onConfigSave(new ConfigSaveEvent($integration));
        $subscriber->onHttpTerminate($this->makeTerminateEvent());
    }

    // ──────────────────────────────────────────────────────────────
    // Error resilience — exception in syncAll must not propagate
    // ──────────────────────────────────────────────────────────────

    public function testSyncExceptionDoesNotPropagateToKernelTerminate(): void
    {
        $syncService = $this->createMock(BulkSyncService::class);
        $syncService->method('syncAll')->willThrowException(new \RuntimeException('DB is down'));

        $integration = $this->integration(published: true, token: 'tok');
        $subscriber  = $this->makeSubscriber($syncService, $integration);

        $subscriber->onConfigSave(new ConfigSaveEvent($integration));

        // Must not throw.
        $subscriber->onHttpTerminate($this->makeTerminateEvent());
        $this->addToAssertionCount(1);
    }

    public function testSyncExceptionDoesNotPropagateToConsoleTerminate(): void
    {
        $syncService = $this->createMock(BulkSyncService::class);
        $syncService->method('syncAll')->willThrowException(new \RuntimeException('network failure'));

        $integration = $this->integration(published: true, token: 'tok');
        $subscriber  = $this->makeSubscriber($syncService, $integration);

        $subscriber->onConfigSave(new ConfigSaveEvent($integration));
        $subscriber->onConsoleTerminate($this->makeConsoleTerminateEvent());
        $this->addToAssertionCount(1);
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    private function makeSubscriber(
        BulkSyncService $syncService,
        Integration $integration,
    ): BulkSyncOnEnableSubscriber {
        return $this->makeSubscriberWithFinder(
            $syncService,
            InsightsPublishedIntegrationFinder::createForTest($integration),
        );
    }

    private function makeSubscriberWithFinder(
        BulkSyncService $syncService,
        InsightsPublishedIntegrationFinder $finder,
    ): BulkSyncOnEnableSubscriber {
        return new BulkSyncOnEnableSubscriber(
            $syncService,
            $finder,
            new InsightsHttpTokenResolver(null),
            new NullLogger(),
        );
    }

    private function mockSyncService(int $expectedCalls): BulkSyncService
    {
        $mock = $this->createMock(BulkSyncService::class);
        $mock->expects($this->exactly($expectedCalls))->method('syncAll')->willReturn(0);

        return $mock;
    }

    private function integration(bool $published, string $token): Integration
    {
        return new Integration(
            $published,
            [],
            InsightsApiConfig::NAME,
            $token !== '' ? [InsightsApiConfig::HTTP_TOKEN_FIELD => $token] : [],
        );
    }

    private function makeTerminateEvent(): TerminateEvent
    {
        return new TerminateEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/'),
            new Response(),
        );
    }

    private function makeConsoleTerminateEvent(): ConsoleTerminateEvent
    {
        $command = $this->createMock(Command::class);
        $command->method('getName')->willReturn('some:command');
        $command->method('getAliases')->willReturn([]);

        return new ConsoleTerminateEvent($command, new ArrayInput([]), new NullOutput(), 0);
    }

    private function makeInstallEvent(string $bundle): PluginInstallEvent
    {
        $plugin = new class($bundle) extends Plugin {
            public function __construct(private string $bundle)
            {
            }

            public function getBundle(): string
            {
                return $this->bundle;
            }

            public function getName(): string
            {
                return $this->bundle;
            }
        };

        return new PluginInstallEvent($plugin);
    }

    private function makeUpdateEvent(string $bundle): PluginUpdateEvent
    {
        $plugin = new class($bundle) extends Plugin {
            public function __construct(private string $bundle)
            {
            }

            public function getBundle(): string
            {
                return $this->bundle;
            }

            public function getName(): string
            {
                return $this->bundle;
            }
        };

        return new PluginUpdateEvent($plugin, '1.0.0');
    }
}
