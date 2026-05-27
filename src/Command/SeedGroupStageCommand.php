<?php

namespace App\Command;

use App\Entity\TournamentGroup;
use App\Repository\TournamentGroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:seed-group-stage', description: 'Create FIFA 2026 group stage groups A-L')]
class SeedGroupStageCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TournamentGroupRepository $groupRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach (range('A', 'L') as $code) {
            $existing = $this->groupRepository->findOneBy(['code' => $code]);
            if ($existing instanceof TournamentGroup) {
                continue;
            }

            $group = (new TournamentGroup())
                ->setCode($code)
                ->setName('Grupo '.$code);

            $this->entityManager->persist($group);
        }

        $this->entityManager->flush();
        $io->success('Grupos A-L creados o actualizados.');

        return Command::SUCCESS;
    }
}
