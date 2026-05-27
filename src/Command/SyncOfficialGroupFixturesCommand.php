<?php

namespace App\Command;

use App\Entity\Fixture;
use App\Repository\FixtureRepository;
use App\Repository\TeamRepository;
use App\Repository\TournamentGroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:sync-official-group-fixtures',
    description: 'Sync group-stage fixtures kickoff times from FIFA official API (UTC).',
)]
class SyncOfficialGroupFixturesCommand extends Command
{
    private const API_URL = 'https://api.fifa.com/api/v3/calendar/matches?language=en&idCompetition=17&idSeason=285023&idStage=289273&count=400';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
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
        $io->text('Source: '.self::API_URL);

        try {
            $response = $this->httpClient->request('GET', self::API_URL);
            $payload = $response->toArray();
        } catch (\Throwable $exception) {
            $io->error('Could not fetch FIFA API: '.$exception->getMessage());

            return Command::FAILURE;
        }

        if (!isset($payload['Results']) || !is_array($payload['Results'])) {
            $io->error('Unexpected FIFA API payload. Missing Results array.');

            return Command::FAILURE;
        }

        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $skipped = 0;

        foreach ($payload['Results'] as $row) {
            $groupCode = $this->extractGroupCode($row);
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

    private function extractGroupCode(array $row): ?string
    {
        $descriptions = $row['GroupName'] ?? [];
        if (!is_array($descriptions)) {
            return null;
        }

        foreach ($descriptions as $description) {
            $text = $description['Description'] ?? null;
            if (!is_string($text)) {
                continue;
            }

            if (preg_match('/Group\s+([A-Z])/i', $text, $matches) === 1) {
                return strtoupper($matches[1]);
            }
        }

        return null;
    }
}
