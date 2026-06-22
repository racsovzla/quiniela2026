<?php

namespace App\Command;

use App\Service\FifaCalendarClient;
use App\Service\SyncLiveFixtureScoresService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync-live-fixture-scores',
    description: 'Sync live scores and finished status for in-progress fixtures from FIFA API.',
)]
class SyncLiveFixtureScoresCommand extends Command
{
    public function __construct(
        private readonly SyncLiveFixtureScoresService $syncLiveFixtureScoresService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show changes without writing to database.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('Sync Live Fixture Scores');
        $io->text('Source: '.FifaCalendarClient::GROUP_STAGE_API_URL);

        try {
            $stats = $this->syncLiveFixtureScoresService->syncLiveScores(dryRun: $dryRun);
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->note('Dry run: no database writes.');
        }

        $io->definitionList(
            ['Checked' => (string) $stats['checked']],
            ['Matched' => (string) $stats['matched']],
            ['Updated' => (string) $stats['updated']],
            ['Finished' => (string) $stats['finished']],
            ['Postponed' => (string) $stats['postponed']],
            ['Suspended' => (string) $stats['suspended']],
            ['Schedules created' => (string) $stats['schedulesCreated']],
            ['Schedules updated' => (string) $stats['schedulesUpdated']],
            ['Skipped' => (string) $stats['skipped']],
            ['Mode' => $dryRun ? 'DRY RUN (no DB writes)' : 'APPLIED'],
        );

        $io->success('Live fixture scores sync completed.');

        return Command::SUCCESS;
    }
}
