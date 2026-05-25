<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Command;

use MauticPlugin\MauticInsightsBundle\Service\BulkSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Runs the full Insights bulk sync from the CLI without kernel.terminate time constraints.
 *
 * Usage:
 *   php bin/console mauticinsights:bulk-sync
 *
 * Useful when the plugin was enabled but kernel.terminate was killed mid-sync
 * (e.g. PHP-FPM max_execution_time reached before all contacts were sent).
 */
#[AsCommand(
    name: 'mauticinsights:bulk-sync',
    description: 'Synchronises all Mautic entities (segments, emails, campaigns, contacts) to the Insights app.',
)]
final class BulkSyncCommand extends Command
{
    public function __construct(
        private BulkSyncService $bulkSyncService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('MauticInsights Bulk Sync');

        try {
            $io->text('Starting sync…');
            $total = $this->bulkSyncService->syncAll();

            if (0 === $total) {
                $io->warning('Sync produced 0 entities — integration may not be published or token is missing.');

                return Command::FAILURE;
            }

            $io->success(sprintf('Sync completed: %d entities dispatched.', $total));

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $io->error('Sync failed: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
