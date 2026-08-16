<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Tests\Command;

use MauticPlugin\MauticInsightsBundle\Command\SegmentReconcileCommand;
use MauticPlugin\MauticInsightsBundle\Service\SegmentReconcileService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class SegmentReconcileCommandTest extends TestCase
{
    public function testCommandIsRegistered(): void
    {
        $service = $this->createMock(SegmentReconcileService::class);
        $command = new SegmentReconcileCommand($service);
        $this->assertSame('mautic:insights:reconcile-segments', $command->getName());
    }

    public function testCommandDisplaysStatsOnSuccess(): void
    {
        $service = $this->createMock(SegmentReconcileService::class);
        $service->method('reconcile')->willReturn([
            'added' => 5,
            'removed' => 3,
            'total_contacts' => 100,
        ]);

        $app = new Application();
        $app->add(new SegmentReconcileCommand($service));

        $tester = new CommandTester($app->find('mautic:insights:reconcile-segments'));
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('5 added, 3 removed across 100 contacts', $tester->getDisplay());
    }

    public function testCommandReturnsFailureOnException(): void
    {
        $service = $this->createMock(SegmentReconcileService::class);
        $service->method('reconcile')->willThrowException(new RuntimeException('DB error'));

        $app = new Application();
        $app->add(new SegmentReconcileCommand($service));

        $tester = new CommandTester($app->find('mautic:insights:reconcile-segments'));
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('DB error', $tester->getDisplay());
    }

    public function testCommandHelpContainsDescription(): void
    {
        $service = $this->createMock(SegmentReconcileService::class);
        $command = new SegmentReconcileCommand($service);

        $desc = $command->getDescription();
        $this->assertNotEmpty($desc);
        $this->assertStringContainsString('Reconciles', $desc);
    }
}
