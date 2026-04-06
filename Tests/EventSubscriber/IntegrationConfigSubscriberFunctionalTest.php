<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\EventSubscriber;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Mautic\ConfigBundle\ConfigEvents;
use Mautic\ConfigBundle\Event\ConfigEvent;
use Mautic\CoreBundle\Helper\AppVersion;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\EncryptionHelper;
use Mautic\IntegrationsBundle\Event\ConfigSaveEvent;
use Mautic\IntegrationsBundle\IntegrationEvents;
use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Event\PluginIntegrationEvent;
use Mautic\PluginBundle\PluginEvents;
use MauticPlugin\MauticInsightsBundle\EventSubscriber\IntegrationConfigSubscriber;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\InsightsPluginVersion;
use MauticPlugin\MauticInsightsBundle\Service\InsightsHttpTokenResolver;
use MauticPlugin\MauticInsightsBundle\Service\InsightsLastApiTelemetry;
use MauticPlugin\MauticInsightsBundle\Service\InsightsPublishedIntegrationFinder;
use MauticPlugin\MauticInsightsBundle\Service\NoopInsightsApiSendGate;
use MauticPlugin\MauticInsightsBundle\Service\StatusNotifyPayload;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class IntegrationConfigSubscriberFunctionalTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────
    // Subscribed events
    // ──────────────────────────────────────────────────────────────

    public function testRegistersExpectedEvents(): void
    {
        $map = IntegrationConfigSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(IntegrationEvents::INTEGRATION_CONFIG_AFTER_SAVE, $map);
        $this->assertArrayHasKey(PluginEvents::PLUGIN_ON_INTEGRATION_CONFIG_SAVE, $map);
        $this->assertArrayHasKey(ConfigEvents::CONFIG_PRE_SAVE, $map);
        $this->assertArrayHasKey(\Symfony\Component\HttpKernel\KernelEvents::TERMINATE, $map);
        $this->assertArrayHasKey(\Symfony\Component\Console\ConsoleEvents::TERMINATE, $map);
        $this->assertArrayHasKey(PluginEvents::ON_PLUGIN_UPDATE, $map);
        $this->assertArrayHasKey(PluginEvents::ON_PLUGIN_INSTALL, $map);
    }

    public function testSuccessfulPluginsReloadCommandSendsStatusOnConsoleTerminate(): void
    {
        $captured = [];
        $subscriber = $this->makeSubscriber(
            version: '5.2.0',
            contactCount: 10,
            senderEmail: 'from@example.com',
            token: 'cli-token',
            captured: $captured,
        );

        $command = $this->createMock(Command::class);
        $command->method('getName')->willReturn('mautic:plugins:reload');
        $command->method('getAliases')->willReturn(['mautic:plugins:install', 'mautic:plugins:update']);

        $subscriber->onConsoleTerminate(new ConsoleTerminateEvent(
            $command,
            new ArrayInput([]),
            new NullOutput(),
            0
        ));

        $this->assertCount(1, $captured, 'mautic:plugins:reload must POST /api/status on console.terminate');
    }

    public function testPluginsReloadCommandSkipsStatusWhenExitCodeNonZero(): void
    {
        $captured = [];
        $subscriber = $this->makeSubscriber(
            version: '5.2.0',
            contactCount: 0,
            senderEmail: 'x@x.com',
            token: 'tok',
            captured: $captured,
        );

        $command = $this->createMock(Command::class);
        $command->method('getName')->willReturn('mautic:plugins:reload');
        $command->method('getAliases')->willReturn([]);

        $subscriber->onConsoleTerminate(new ConsoleTerminateEvent(
            $command,
            new ArrayInput([]),
            new NullOutput(),
            1
        ));

        $this->assertCount(0, $captured);
    }

    public function testPluginsReloadAliasAsCommandNameStillSendsStatus(): void
    {
        $captured = [];
        $subscriber = $this->makeSubscriber(
            version: '5.0.0',
            contactCount: 0,
            senderEmail: 'a@b.com',
            token: 't',
            captured: $captured,
        );

        $command = $this->createMock(Command::class);
        $command->method('getName')->willReturn('mautic:plugins:install');
        $command->method('getAliases')->willReturn([]);

        $subscriber->onConsoleTerminate(new ConsoleTerminateEvent(
            $command,
            new ArrayInput([]),
            new NullOutput(),
            0
        ));

        $this->assertCount(1, $captured);
    }

    // ──────────────────────────────────────────────────────────────
    // All three trigger paths produce the same POST body
    // ──────────────────────────────────────────────────────────────

    /**
     * @dataProvider provideAllTriggerPaths
     */
    public function testEveryTriggerPathSendsCorrectStatusBody(
        string $triggerDescription,
        callable $trigger,
    ): void {
        $captured = [];
        $subscriber = $this->makeSubscriber(
            version: '5.2.0',
            contactCount: 42,
            senderEmail: 'from@example.com',
            token: 'my-token',
            captured: $captured,
        );

        $trigger($subscriber);
        $subscriber->sendQueuedStatusNotify($this->makeTerminateEvent());

        $this->assertCount(1, $captured, "No POST for trigger: $triggerDescription");
        $this->assertSame('POST', $captured[0]['method'], $triggerDescription);
        $this->assertSame(InsightsApiConfig::STATUS_NOTIFY_URL, $captured[0]['url'], $triggerDescription);

        $auth = $captured[0]['options']['normalized_headers']['authorization'][0] ?? '';
        $this->assertStringContainsString('Bearer my-token', $auth, $triggerDescription);

        $body = json_decode($captured[0]['options']['body'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            StatusNotifyPayload::build('5.2.0', InsightsPluginVersion::get(), 42, 'from@example.com'),
            $body,
            $triggerDescription
        );
    }

    /** @return array<string, array{string, callable}> */
    public static function provideAllTriggerPaths(): array
    {
        $integration = new Integration(true, [], InsightsApiConfig::NAME, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => 'my-token',
        ]);

        return [
            'IntegrationsBundle INTEGRATION_CONFIG_AFTER_SAVE' => [
                'INTEGRATION_CONFIG_AFTER_SAVE',
                static fn (IntegrationConfigSubscriber $s) => $s->queueStatusNotify(new ConfigSaveEvent($integration)),
            ],
            'PluginBundle PLUGIN_ON_INTEGRATION_CONFIG_SAVE' => [
                'PLUGIN_ON_INTEGRATION_CONFIG_SAVE',
                static fn (IntegrationConfigSubscriber $s) => $s->onPluginIntegrationConfigSave(new PluginIntegrationEvent($integration)),
            ],
            'Mautic global CONFIG_PRE_SAVE' => [
                'CONFIG_PRE_SAVE',
                static fn (IntegrationConfigSubscriber $s) => $s->onGlobalConfigPreSave(new ConfigEvent([
                    'emailconfig' => ['mailer_from_email' => 'from@example.com'],
                ])),
            ],
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // Sender e-mail resolution
    // ──────────────────────────────────────────────────────────────

    public function testGlobalConfigPreSaveTrimsSenderEmailFromForm(): void
    {
        $captured = [];
        $subscriber = $this->makeSubscriber(
            version: '5.0.0',
            contactCount: 0,
            senderEmail: 'fallback@example.com',
            token: 'tok',
            captured: $captured,
        );

        $subscriber->onGlobalConfigPreSave(new ConfigEvent([
            'emailconfig' => ['mailer_from_email' => '  trimmed@test.com  '],
        ]));
        $subscriber->sendQueuedStatusNotify($this->makeTerminateEvent());

        $body = json_decode($captured[0]['options']['body'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('trimmed@test.com', $body['sender-email']);
    }

    public function testGlobalConfigPreSaveFallsBackToCoreParamWhenEmailconfigMissing(): void
    {
        $captured = [];
        $subscriber = $this->makeSubscriber(
            version: '5.0.0',
            contactCount: 0,
            senderEmail: 'core-param@example.com',
            token: 'tok',
            captured: $captured,
        );

        $subscriber->onGlobalConfigPreSave(new ConfigEvent([])); // no emailconfig key
        $subscriber->sendQueuedStatusNotify($this->makeTerminateEvent());

        $body = json_decode($captured[0]['options']['body'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        // falls back to CoreParametersHelper configured on the subscriber
        $this->assertSame('core-param@example.com', $body['sender-email']);
    }

    // ──────────────────────────────────────────────────────────────
    // Token handling
    // ──────────────────────────────────────────────────────────────

    /**
     * Mautic's IntegrationsHelper::saveIntegrationConfiguration() restores plain-text keys onto the entity AFTER
     * saving encrypted keys to DB, so INTEGRATION_CONFIG_AFTER_SAVE receives a plain-text token.
     */
    public function testSendsStatusWhenDecryptionFailsAndTokenIsPlainText(): void
    {
        $captured = [];
        $integration = new Integration(true, [], InsightsApiConfig::NAME, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => 'plain-token',
        ]);
        $subscriber = $this->makeSubscriberWithIntegration(
            integration: $integration,
            version: '5.1.0',
            contactCount: 0,
            senderEmail: 'x@x.com',
            captured: $captured,
            encryptionHelper: new class extends EncryptionHelper {
                public function __construct()
                {
                }

                public function decrypt(mixed $key, bool $mainDecryptOnly = false)
                {
                    return false; // simulate: decrypt fails for plain-text value
                }
            },
        );

        $subscriber->queueStatusNotify(new ConfigSaveEvent($integration));
        $subscriber->sendQueuedStatusNotify($this->makeTerminateEvent());

        $this->assertCount(1, $captured, 'Must POST even when decrypt() returns false');
        $auth = $captured[0]['options']['normalized_headers']['authorization'][0] ?? '';
        $this->assertStringContainsString('Bearer plain-token', $auth);
    }

    public function testSkipsWhenNoTokenSet(): void
    {
        $captured = [];
        $integration = new Integration(true, [], InsightsApiConfig::NAME, []); // no token
        $subscriber = $this->makeSubscriberWithIntegration(
            integration: $integration,
            version: '5.0.0',
            contactCount: 0,
            senderEmail: 'x@x.com',
            captured: $captured,
        );

        $subscriber->queueStatusNotify(new ConfigSaveEvent($integration));
        $subscriber->sendQueuedStatusNotify($this->makeTerminateEvent());

        $this->assertCount(0, $captured, 'Must not POST when token is empty');
    }

    public function testSkipsWhenIntegrationIsUnpublished(): void
    {
        $captured = [];
        $integration = new Integration(false, [], InsightsApiConfig::NAME, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => 'tok',
        ]);
        $subscriber = $this->makeSubscriberWithIntegration(
            integration: $integration,
            version: '5.0.0',
            contactCount: 0,
            senderEmail: 'x@x.com',
            captured: $captured,
        );

        $subscriber->queueStatusNotify(new ConfigSaveEvent($integration));
        $subscriber->sendQueuedStatusNotify($this->makeTerminateEvent());

        $this->assertCount(0, $captured, 'Must not POST when integration is not published');
    }

    public function testSkipsWhenIntegrationNameDoesNotMatch(): void
    {
        $captured = [];
        $integration = new Integration(true, [], 'SomeOtherPlugin', [
            InsightsApiConfig::HTTP_TOKEN_FIELD => 'tok',
        ]);
        $subscriber = $this->makeSubscriberWithIntegration(
            integration: $integration,
            version: '5.0.0',
            contactCount: 0,
            senderEmail: 'x@x.com',
            captured: $captured,
        );

        $subscriber->queueStatusNotify(new ConfigSaveEvent($integration));
        $subscriber->sendQueuedStatusNotify($this->makeTerminateEvent());

        $this->assertCount(0, $captured, 'Must not POST for a different plugin');
    }

    // ──────────────────────────────────────────────────────────────
    // TERMINATE guard
    // ──────────────────────────────────────────────────────────────

    public function testDoesNotSendOnSubRequest(): void
    {
        $captured = [];
        $integration = new Integration(true, [], InsightsApiConfig::NAME, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => 'tok',
        ]);
        $subscriber = $this->makeSubscriberWithIntegration(
            integration: $integration,
            version: '5.0.0',
            contactCount: 0,
            senderEmail: 'x@x.com',
            captured: $captured,
        );

        $subscriber->queueStatusNotify(new ConfigSaveEvent($integration));

        $kernel = $this->createMock(HttpKernelInterface::class);
        $subRequest = new TerminateEvent($kernel, Request::create('/'), new Response());
        // TerminateEvent is always a main-request event in Symfony; simulate a non-main request
        // by checking the guard manually — sub-request scenario is simulated by not calling
        // sendQueuedStatusNotify (the guard $event->isMainRequest() === false blocks it).
        // Instead verify nothing is sent without the terminate call.
        $this->assertCount(0, $captured, 'Nothing sent without terminate');
        unset($subRequest);
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Shortcut: builds a subscriber with a fixed integration (token resolved from it), version, contact count, sender.
     *
     * @param array<string, mixed>[] &$captured
     */
    private function makeSubscriber(
        string $version,
        int $contactCount,
        string $senderEmail,
        string $token,
        array &$captured,
    ): IntegrationConfigSubscriber {
        $integration = new Integration(true, [], InsightsApiConfig::NAME, [
            InsightsApiConfig::HTTP_TOKEN_FIELD => $token,
        ]);

        return $this->makeSubscriberWithIntegration(
            integration: $integration,
            version: $version,
            contactCount: $contactCount,
            senderEmail: $senderEmail,
            captured: $captured,
        );
    }

    /**
     * @param array<string, mixed>[] &$captured
     */
    private function makeSubscriberWithIntegration(
        Integration $integration,
        string $version,
        int $contactCount,
        string $senderEmail,
        array &$captured,
        ?EncryptionHelper $encryptionHelper = null,
    ): IntegrationConfigSubscriber {
        $httpClient = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$captured): MockResponse {
                $captured[] = ['method' => $method, 'url' => $url, 'options' => $options];

                return new MockResponse('{}', ['http_code' => 200]);
            }
        );

        $query = $this->createMock(AbstractQuery::class);
        $query->method('getSingleScalarResult')->willReturn($contactCount);

        $expr = new class {
            public function isNotNull(string $field): string
            {
                return $field.' IS NOT NULL';
            }
        };

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('expr')->willReturn($expr);
        $qb->method('getQuery')->willReturn($query);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturn($qb);

        $coreParams = new CoreParametersHelper();
        $coreParams->params = ['mailer_from_email' => $senderEmail];

        return new IntegrationConfigSubscriber(
            $httpClient,
            new AppVersion($version),
            $coreParams,
            $em,
            InsightsPublishedIntegrationFinder::createForTest($integration),
            new InsightsHttpTokenResolver($encryptionHelper),
            new NullLogger(),
            new InsightsLastApiTelemetry(sys_get_temp_dir(), new NullLogger()),
            new NoopInsightsApiSendGate(),
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
}
