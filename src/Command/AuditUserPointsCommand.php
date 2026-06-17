<?php

namespace App\Command;

use App\Repository\FixtureRepository;
use App\Repository\PredictionRepository;
use App\Repository\UserRepository;
use App\Service\ScoringService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:audit-user-points', description: 'Compare leaderboard total vs audit matrix sum for a user')]
class AuditUserPointsCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly PredictionRepository $predictionRepository,
        private readonly FixtureRepository $fixtureRepository,
        private readonly ScoringService $scoringService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Partial user name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filter = (string) $input->getArgument('name');

        $user = null;
        foreach ($this->userRepository->findAll() as $candidate) {
            if (stripos($candidate->getName(), $filter) !== false) {
                $user = $candidate;
                break;
            }
        }

        if (!$user) {
            $io->error(sprintf('No user matching "%s"', $filter));

            return Command::FAILURE;
        }

        $userId = $user->getId();
        $leaderboardTotal = 0;
        foreach ($this->scoringService->leaderboard() as $row) {
            if ($row['userId'] === $userId) {
                $leaderboardTotal = $row['points'];
                break;
            }
        }

        $io->title(sprintf('%s (#%d) — leaderboard: %d pts', $user->getName(), $userId, $leaderboardTotal));
        $io->text(sprintf(
            'Pago validado: %s',
            $user->getPaymentValidatedAt()?->format('Y-m-d H:i:s T') ?? 'NO',
        ));

        $auditFixtures = $this->fixtureRepository->findFinishedOrdered();
        $auditSum = 0;
        $rows = [];

        foreach ($auditFixtures as $fixture) {
            $prediction = $this->predictionRepository->findOneByUserAndFixture($user, $fixture);
            $pts = $prediction ? $this->scoringService->calculatePoints($prediction) : 0;
            $auditSum += $pts;
            $rows[] = [
                $fixture->getKickoffAt()->format('Y-m-d H:i'),
                sprintf('%s-%s', $fixture->getHomeTeam()?->getCode(), $fixture->getAwayTeam()?->getCode()),
                sprintf('%d-%d', $fixture->getHomeScore(), $fixture->getAwayScore()),
                $prediction
                    ? sprintf('%d-%d', $prediction->getPredictedHomeScore(), $prediction->getPredictedAwayScore())
                    : '-',
                (string) $pts,
            ];
        }

        $io->table(['Fecha', 'Partido', 'Resultado', 'Predicción', 'Pts'], $rows);
        $io->text(sprintf('Suma auditoría (%d partidos): %d', count($auditFixtures), $auditSum));

        $officialFromAllPredictions = 0;
        $orphanPoints = [];
        foreach ($this->predictionRepository->findByUserWithFixture($user) as $prediction) {
            $pts = $this->scoringService->calculatePoints($prediction);
            $officialFromAllPredictions += $pts;
            if ($pts <= 0) {
                continue;
            }
            $fixture = $prediction->getFixture();
            if (!$fixture || $fixture->getStatus() !== 'finished') {
                $orphanPoints[] = sprintf(
                    'status=%s kickoff=%s %s vs %s => %d pts',
                    $fixture?->getStatus() ?? '?',
                    $fixture?->getKickoffAt()->format('Y-m-d H:i') ?? '?',
                    $fixture?->getHomeTeam()?->getCode(),
                    $fixture?->getAwayTeam()?->getCode(),
                    $pts,
                );
            }
        }

        $io->section('Verificación');
        $io->listing([
            sprintf('Leaderboard buildRows: %d', $leaderboardTotal),
            sprintf('Suma calculatePoints (todas predicciones): %d', $officialFromAllPredictions),
            sprintf('Suma matriz auditoría: %d', $auditSum),
            sprintf('Diferencia leaderboard - auditoría: %d', $leaderboardTotal - $auditSum),
        ]);

        if ($orphanPoints !== []) {
            $io->warning('Puntos en partidos no finalizados (no deberían contar en leaderboard):');
            $io->listing($orphanPoints);
        }

        if ($leaderboardTotal !== $auditSum) {
            $io->error('INCONSISTENCIA: el leaderboard y la auditoría no cuadran.');

            return Command::FAILURE;
        }

        $io->success('Leaderboard y auditoría coinciden.');

        return Command::SUCCESS;
    }
}
