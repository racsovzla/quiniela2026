<?php

namespace App\Command;

use App\Entity\Fixture;
use App\Repository\FixtureRepository;
use App\Repository\TeamRepository;
use App\Repository\TournamentGroupRepository;
use App\Service\FifaCalendarClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync-official-group-fixtures',
    description: 'Sync group-stage fixtures kickoff times from FIFA official API (UTC).',
)]
class SyncOfficialGroupFixturesCommand extends Command
{
    public function __construct(
        private readonly FifaCalendarClient $fifaCalendarClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly TournamentGroupRepository $groupRepository,
        private readonly TeamRepository $teamRepository,
        private readonly FixtureRepository $fixtureRepository,
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

        $io->title('Sync Official FIFA Group Fixtures');
        $io->text('Source: '.FifaCalendarClient::GROUP_STAGE_API_URL);

        try {
            $rows = $this->fifaCalendarClient->fetchGroupStageMatches();
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $groupCode = $this->fifaCalendarClient->extractGroupCode($row);
            $homeCode = $row['Home']['Abbreviation'] ?? null;
            $awayCode = $row['Away']['Abbreviation'] ?? null;
            $kickoffUtc = $row['Date'] ?? null;

            if (!is_string($groupCode) || !is_string($homeCode) || !is_string($awayCode) || !is_string($kickoffUtc)) {
                $skipped++;
                continue;
            }

            $group = $this->groupRepository->findOneBy(['code' => $groupCode]);
            $homeTeam = $this->teamRepository->findOneBy(['code' => strtoupper($homeCode)]);
            $awayTeam = $this->teamRepository->findOneBy(['code' => strtoupper($awayCode)]);

            if (!$group || !$homeTeam || !$awayTeam) {
                $skipped++;
                continue;
            }

            try {
                $kickoffAt = new \DateTimeImmutable($kickoffUtc, new \DateTimeZone('UTC'));
            } catch (\Throwable) {
                $skipped++;
                continue;
            }

            $fixture = $this->fixtureRepository->findOneBy([
                'group' => $group,
                'homeTeam' => $homeTeam,
                'awayTeam' => $awayTeam,
            ]);

            if (!$fixture instanceof Fixture) {
                $fixture = (new Fixture())
                    ->setGroup($group)
                    ->setHomeTeam($homeTeam)
                    ->setAwayTeam($awayTeam)
                    ->setKickoffAt($kickoffAt)
                    ->setStatus(Fixture::STATUS_SCHEDULED);

                $this->entityManager->persist($fixture);
                $created++;
                continue;
            }

            if ($fixture->getKickoffAt()?->format('Y-m-d H:i:s') === $kickoffAt->format('Y-m-d H:i:s')) {
                $unchanged++;
                continue;
            }

            $fixture->setKickoffAt($kickoffAt);
            $updated++;
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        } else {
            $this->entityManager->clear();
        }

        $io->definitionList(
            ['Created' => (string) $created],
            ['Updated' => (string) $updated],
            ['Unchanged' => (string) $unchanged],
            ['Skipped' => (string) $skipped],
            ['Mode' => $dryRun ? 'DRY RUN (no DB writes)' : 'APPLIED'],
        );

        $io->success('Official fixtures sync completed.');

        return Command::SUCCESS;
    }
}
