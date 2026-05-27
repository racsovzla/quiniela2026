<?php

namespace App\Command;

use App\Service\SimulationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:simulation:seed', description: 'Creates simulation users and fills random predictions.')]
class SeedSimulationDataCommand extends Command
{
    public function __construct(private readonly SimulationService $simulationService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('users', null, InputOption::VALUE_REQUIRED, 'How many users to seed', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $users = max(1, (int) $input->getOption('users'));

        $result = $this->simulationService->seedUsersAndPredictions($users);

        $io->success(sprintf(
            'Seed complete. usersCreated=%d predictionsCreated=%d targetUsers=%d',
            $result['createdUsers'],
            $result['createdPredictions'],
            $result['totalUsers']
        ));

        return Command::SUCCESS;
    }
}