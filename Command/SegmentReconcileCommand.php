<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Command;

use MauticPlugin\MauticInsightsBundle\Service\SegmentReconcileService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Diffs `lead_lists_leads` against the last-known checkpoint and emits
 * synthetic {@code mautic.lead_list_change} events for differences.
 *
 * Safe to run as a cron job — idempotent, checkpoint-based.
 *
 * Usage:
 *   php bin/console mautic:insights:reconcile-segments
 *
 * After a `mautic:segments:update` (batch rebuild) to fix stale segment
 * memberships in Insights without waiting for the next real-time event
 * per contact.
 */
#[AsCommand(
    name: 'mautic:insights:reconcile-segments',
    description: 'Reconciles segment memberships against lead_lists_leads and emits synthetic list-change events.',
)]
final class SegmentReconcileCommand extends Command
{
    public function __construct(
        private readonly SegmentReconcileService $reconcileService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('MauticInsights — Segment Reconcile');

        try {
            $stats = $this->reconcileService->reconcile();

            $io->success(sprintf(
                'Done — %d added, %d removed across %d contacts.',
                $stats['added'],
                $stats['removed'],
                $stats['total_contacts']
            ));

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $io->error('Segment reconcile failed: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
