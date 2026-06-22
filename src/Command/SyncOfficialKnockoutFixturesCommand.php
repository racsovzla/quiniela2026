<?php

namespace App\Command;

use App\Service\FifaCalendarClient;
use App\Service\FifaFixtureDiscoveryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync-official-knockout-fixtures',
    description: 'Sync knockout-stage fixtures from FIFA official API (UTC).',
)]
class SyncOfficialKnockoutFixturesCommand extends Command
{
    public function __construct(
        private readonly FifaFixtureDiscoveryService $fifaFixtureDiscoveryService,
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

        $io->title('Sync Official FIFA Knockout Fixtures');
        $io->text('Source: '.FifaCalendarClient::ALL_MATCHES_API_URL);

        $stats = $this->fifaFixtureDiscoveryService->importNewFixtures($dryRun, knockoutOnly: true);

        $io->definitionList(
            ['Created' => (string) $stats['created']],
            ['Updated' => (string) $stats['updated']],
            ['Skipped' => (string) $stats['skipped']],
            ['Mode' => $dryRun ? 'DRY RUN (no DB writes)' : 'APPLIED'],
        );

        $io->success('Official knockout fixtures sync completed.');

        return Command::SUCCESS;
    }
}
