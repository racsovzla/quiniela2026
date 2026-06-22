<?php

namespace App\Command;

use App\Entity\Fixture;
use App\Repository\FixtureRepository;
use App\Repository\TeamRepository;
use App\Service\FifaCalendarClient;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly FifaCalendarClient $fifaCalendarClient,
        private readonly EntityManagerInterface $entityManager,
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

        $io->title('Sync Official FIFA Knockout Fixtures');
        $io->text('Source: '.FifaCalendarClient::ALL_MATCHES_API_URL);

        try {
            $rows = $this->fifaCalendarClient->fetchAllMatches();
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $stage = $this->fifaCalendarClient->extractStageKey($row);
            if ($stage === Fixture::STAGE_GROUP) {
                continue;
            }

            $homeCode = $this->fifaCalendarClient->teamCode($row, 'Home');
            $awayCode = $this->fifaCalendarClient->teamCode($row, 'Away');
            $kickoffUtc = $this->fifaCalendarClient->kickoffIso($row);
            $fifaMatchId = $this->fifaCalendarClient->extractMatchId($row);

            if ($homeCode === null || $awayCode === null || $kickoffUtc === null || $fifaMatchId === null) {
                ++$skipped;
                continue;
            }

            $homeTeam = $this->teamRepository->findOneBy(['code' => $homeCode]);
            $awayTeam = $this->teamRepository->findOneBy(['code' => $awayCode]);

            if (!$homeTeam || !$awayTeam) {
                ++$skipped;
                continue;
            }

            try {
                $kickoffAt = new \DateTimeImmutable($kickoffUtc, new \DateTimeZone('UTC'));
            } catch (\Throwable) {
                ++$skipped;
                continue;
            }

            $fixture = $this->fixtureRepository->findOneByFifaMatchId($fifaMatchId);
            if (!$fixture instanceof Fixture) {
                $fixture = $this->fixtureRepository->findOneByTeamsAndStage($homeTeam, $awayTeam, $stage);
            }

            if (!$fixture instanceof Fixture) {
                $fixture = (new Fixture())
                    ->setHomeTeam($homeTeam)
                    ->setAwayTeam($awayTeam)
                    ->setGroup(null)
                    ->setStage($stage)
                    ->setFifaMatchId($fifaMatchId)
                    ->setKickoffAt($kickoffAt)
                    ->setStatus(Fixture::STATUS_SCHEDULED);

                if (!$dryRun) {
                    $this->entityManager->persist($fixture);
                }
                ++$created;
                continue;
            }

            $changed = false;

            if ($fixture->getKickoffAt()?->format('Y-m-d H:i:s') !== $kickoffAt->format('Y-m-d H:i:s')) {
                $fixture->setKickoffAt($kickoffAt);
                $changed = true;
            }

            if ($fixture->getStage() !== $stage) {
                $fixture->setStage($stage);
                $changed = true;
            }

            if ($fixture->getFifaMatchId() !== $fifaMatchId) {
                $fixture->setFifaMatchId($fifaMatchId);
                $changed = true;
            }

            if (!$changed) {
                ++$unchanged;
                continue;
            }

            if (!$dryRun) {
                $this->entityManager->persist($fixture);
            }
            ++$updated;
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

        $io->success('Official knockout fixtures sync completed.');

        return Command::SUCCESS;
    }
}
