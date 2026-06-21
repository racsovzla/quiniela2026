<?php

namespace App\Command;

use App\Service\SendDueFixturePredictionEmailsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:send-due-prediction-emails',
    description: 'Send prediction summary emails for fixtures whose window just closed.',
)]
class SendDuePredictionEmailsCommand extends Command
{
    public function __construct(
        private readonly SendDueFixturePredictionEmailsService $sendDueFixturePredictionEmailsService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List due fixtures without sending email.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $stats = $this->sendDueFixturePredictionEmailsService->dispatchDueEmails(dryRun: $dryRun);

        if ($dryRun) {
            $io->note('Dry run: no emails sent and nothing persisted.');
        }

        $io->success(sprintf(
            'Processed %d fixture(s): %d sent, %d skipped, %d recipient(s).',
            $stats['processed'],
            $stats['sent'],
            $stats['skipped'],
            $stats['recipientCount'],
        ));

        if (!$dryRun && ($stats['whatsAppFailed'] ?? 0) > 0) {
            $io->warning(sprintf(
                'WhatsApp no se pudo enviar para %d partido(s). Revisa CALLMEBOT_PHONE y CALLMEBOT_APIKEY.',
                $stats['whatsAppFailed'],
            ));
        }

        return Command::SUCCESS;
    }
}
