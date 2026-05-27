<?php

namespace App\Command;

use App\Entity\Team;
use App\Repository\TeamRepository;
use App\Repository\TournamentGroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:import-group-teams', description: 'Import teams by group from CSV: group_code,team_name,team_code')]
class ImportGroupTeamsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TournamentGroupRepository $groupRepository,
        private readonly TeamRepository $teamRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('csvPath', InputArgument::REQUIRED, 'CSV path with columns: group_code,team_name,team_code');
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

            if (count($row) < 3) {
                fclose($handle);
                $io->error('Invalid CSV format at row '.$rowNum.'.');

                return Command::FAILURE;
            }

            $groupCode = strtoupper(trim((string) $row[0]));
            $teamName = trim((string) $row[1]);
            $teamCode = strtoupper(trim((string) $row[2]));

            $group = $this->groupRepository->findOneBy(['code' => $groupCode]);
            if (!$group) {
                fclose($handle);
                $io->error('Group '.$groupCode.' not found. Seed groups first.');

                return Command::FAILURE;
            }

            $team = $this->teamRepository->findOneBy(['code' => $teamCode]);
            if (!$team instanceof Team) {
                $team = new Team();
                $this->entityManager->persist($team);
            }

            $team
                ->setName($teamName)
                ->setCode($teamCode)
                ->setGroup($group);
        }

        fclose($handle);
        $this->entityManager->flush();
        $io->success('Teams imported successfully.');

        return Command::SUCCESS;
    }
}
