<?php

namespace App\Command;

use App\Entity\Fixture;
use App\Repository\FixtureRepository;
use App\Repository\TeamRepository;
use App\Repository\TournamentGroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:import-group-fixtures', description: 'Import group fixtures from CSV: group_code,kickoff_at_utc,home_code,away_code')]
class ImportGroupFixturesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TournamentGroupRepository $groupRepository,
        private readonly TeamRepository $teamRepository,
        private readonly FixtureRepository $fixtureRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('csvPath', InputArgument::REQUIRED, 'CSV path with columns: group_code,kickoff_at_utc,home_code,away_code');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $csvPath = (string) $input->getArgument('csvPath');

        if (!is_file($csvPath)) {
            $io->error('CSV file not found.');

            return Command::FAILURE;
        }

        $handle = fopen($csvPath, 'rb');
        if ($handle === false) {
            $io->error('Cannot open CSV file.');

            return Command::FAILURE;
        }

        $rowNum = 0;
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $rowNum++;
            if ($rowNum === 1 && isset($row[0]) && mb_strtolower(trim((string) $row[0])) === 'group_code') {
                continue;
            }

            if (count($row) < 4) {
                fclose($handle);
                $io->error('Invalid CSV format at row '.$rowNum.'.');

                return Command::FAILURE;
            }

            $groupCode = strtoupper(trim((string) $row[0]));
            $kickoffRaw = trim((string) $row[1]);
            $homeCode = strtoupper(trim((string) $row[2]));
            $awayCode = strtoupper(trim((string) $row[3]));

            $group = $this->groupRepository->findOneBy(['code' => $groupCode]);
            if (!$group) {
                fclose($handle);
                $io->error('Group '.$groupCode.' not found.');

                return Command::FAILURE;
            }

            $home = $this->teamRepository->findOneBy(['code' => $homeCode]);
            $away = $this->teamRepository->findOneBy(['code' => $awayCode]);
            if (!$home || !$away) {
                fclose($handle);
                $io->error('Team code not found at row '.$rowNum.'.');

                return Command::FAILURE;
            }

            try {
                $kickoffAt = new \DateTimeImmutable($kickoffRaw, new \DateTimeZone('UTC'));
            } catch (\Exception) {
                fclose($handle);
                $io->error('Invalid datetime at row '.$rowNum.'. Use YYYY-MM-DD HH:MM format in UTC.');

                return Command::FAILURE;
            }

            $existing = $this->fixtureRepository->findOneBy([
                'group' => $group,
                'homeTeam' => $home,
                'awayTeam' => $away,
                'kickoffAt' => $kickoffAt,
            ]);

            if ($existing instanceof Fixture) {
                continue;
            }

            $fixture = (new Fixture())
                ->setGroup($group)
                ->setHomeTeam($home)
                ->setAwayTeam($away)
                ->setKickoffAt($kickoffAt)
                ->setStatus(Fixture::STATUS_SCHEDULED);

            $this->entityManager->persist($fixture);
        }

        fclose($handle);
        $this->entityManager->flush();

        $io->success('Group fixtures imported successfully.');

        return Command::SUCCESS;
    }
}
