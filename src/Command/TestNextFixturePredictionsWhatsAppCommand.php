<?php

namespace App\Command;

use App\Service\TestNextFixturePredictionsWhatsAppService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-next-fixture-predictions-whatsapp',
    description: 'Send a test WhatsApp with predictions for the next not-started fixture (admin only).',
)]
class TestNextFixturePredictionsWhatsAppCommand extends Command
{
    public function __construct(
        private readonly TestNextFixturePredictionsWhatsAppService $testNextFixturePredictionsWhatsAppService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the message without sending WhatsApp.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $result = $this->testNextFixturePredictionsWhatsAppService->sendToAdmin(dryRun: $dryRun);

        if ($result['error'] !== null && $result['fixture'] === null && $result['admin'] === null) {
            $io->error($result['error']);

            return Command::FAILURE;
        }

        if ($result['admin'] !== null) {
            $io->text(sprintf(
                'Administrador: %s <%s>',
                $result['admin']->getName(),
                $result['admin']->getEmail(),
            ));
        }

        if ($result['fixture'] !== null) {
            $io->text(sprintf(
                'Partido: %s',
                $this->testNextFixturePredictionsWhatsAppService->describeFixture($result['fixture']),
            ));
            $io->text(sprintf('Pronósticos: %d', $result['predictionCount']));
        }

        if ($result['message'] !== null) {
            $io->section('Mensaje');
            $io->writeln($result['message']);
        }

        if ($result['error'] !== null) {
            $io->error($result['error']);

            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->note('Dry run: no se envió WhatsApp.');
            $io->success('Vista previa generada.');

            return Command::SUCCESS;
        }

        $io->success('WhatsApp de prueba enviado al administrador (CALLMEBOT_PHONE).');

        return Command::SUCCESS;
    }
}
