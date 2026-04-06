<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\Service;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Mautic\CoreBundle\Model\NotificationModel;
use Mautic\PluginBundle\Entity\Integration;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInsightsBundle\InsightsApiConfig;
use MauticPlugin\MauticInsightsBundle\Service\InsightsApiOutageCoordinator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

final class InsightsApiOutageCoordinatorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/mautic-insights-outage-test-'.uniqid('', true);
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (['mautic_insights_api_outage.json', 'mautic_insights_api_outage.lock'] as $f) {
            $p = $this->dir.'/'.$f;
            if (is_file($p)) {
                @unlink($p);
            }
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testShouldNotSkipInitially(): void
    {
        $c = $this->makeCoordinator();

        $this->assertFalse($c->shouldSkipHttpThisFlush());
    }

    public function testFailedFlushIncreasesBackoffAndSkip(): void
    {
        $c = $this->makeCoordinator();

        $c->afterFlush(true, false);
        $this->assertTrue($c->shouldSkipHttpThisFlush());

        $raw = json_decode((string) file_get_contents($this->dir.'/mautic_insights_api_outage.json'), true);
        $this->assertSame(1, (int) ($raw['consecutive_failed_flushes'] ?? 0));
        $this->assertNotNull($raw['next_attempt_epoch'] ?? null);
        $this->assertIsInt($raw['next_attempt_epoch']);
        $this->assertGreaterThan(time(), (int) $raw['next_attempt_epoch']);
    }

    public function testSuccessClearsBackoff(): void
    {
        $c = $this->makeCoordinator();

        $c->afterFlush(true, false);
        $this->assertTrue($c->shouldSkipHttpThisFlush());

        $c->afterFlush(true, true);
        $this->assertFalse($c->shouldSkipHttpThisFlush());

        $raw = json_decode((string) file_get_contents($this->dir.'/mautic_insights_api_outage.json'), true);
        $this->assertNull($raw['outage_began_at'] ?? null);
        $this->assertSame(0, (int) ($raw['consecutive_failed_flushes'] ?? -1));
        $this->assertNull($raw['next_attempt_epoch'] ?? null);
    }

    public function testTickAfter24HoursUnpublishesNotifiesAndPersistsShutdown(): void
    {
        $integration = new Integration(true, [], InsightsApiConfig::NAME, []);
        $user = new User(1, true);

        $intRepo = $this->createMock(ObjectRepository::class);
        $intRepo->method('findOneBy')->with(['name' => InsightsApiConfig::NAME])->willReturn($integration);

        $userRepo = $this->createMock(ObjectRepository::class);
        $userRepo->method('findAll')->willReturn([$user]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnMap([
            [Integration::class, $intRepo],
            [User::class, $userRepo],
        ]);
        $em->expects($this->once())->method('flush');

        $notifications = new NotificationModel();
        $c = $this->makeCoordinator($em, $notifications);

        $began = (new DateTimeImmutable('-25 hours', new DateTimeZone('UTC')))->format(\DATE_ATOM);
        file_put_contents(
            $this->dir.'/mautic_insights_api_outage.json',
            (string) json_encode([
                'outage_began_at' => $began,
                'consecutive_failed_flushes' => 3,
                'next_attempt_epoch' => time() + 99999,
                'shutdown_notified_at' => null,
            ], JSON_THROW_ON_ERROR)
        );

        $c->tick();

        $this->assertFalse($integration->getIsPublished());
        $this->assertCount(1, $notifications->added);
        $this->assertSame(1, $notifications->added[0]['user']->getId());

        $raw = json_decode((string) file_get_contents($this->dir.'/mautic_insights_api_outage.json'), true);
        $this->assertNotNull($raw['shutdown_notified_at'] ?? null);
    }

    public function testResetStateOnIntegrationConfigChange(): void
    {
        $c = $this->makeCoordinator();
        $c->afterFlush(true, false);
        $this->assertTrue($c->shouldSkipHttpThisFlush());

        $c->resetStateOnIntegrationConfigChange();
        $this->assertFalse($c->shouldSkipHttpThisFlush());
    }

    private function makeCoordinator(?EntityManagerInterface $em = null, ?NotificationModel $notifications = null): InsightsApiOutageCoordinator
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new InsightsApiOutageCoordinator(
            $this->dir,
            $em ?? $this->createMock(EntityManagerInterface::class),
            $notifications ?? new NotificationModel(),
            $translator,
            new NullLogger()
        );
    }
}
