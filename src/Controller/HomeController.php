<?php

namespace App\Controller;

use App\Entity\Fixture;
use App\Entity\User;
use App\Repository\FixtureRepository;
use App\Repository\PredictionRepository;
use App\Service\FixturePredictionViewService;
use App\Service\LeaderboardLockService;
use App\Service\ScoringService;
use App\Service\TournamentMatchBudgetService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class HomeController extends AbstractController
{
    /**
     * How many upcoming fixtures to show when there are no matches today.
     */
    private const FALLBACK_LIMIT = 8;

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(
        ScoringService $scoringService,
        TournamentMatchBudgetService $matchBudgetService,
        LeaderboardLockService $lockService,
    ): Response {
        $lockedWinners = [];

        if ($this->getUser()) {
            $budget = $matchBudgetService->current();
            $rows = $lockService->annotate(
                $lockService->withSharedPositions($scoringService->leaderboard()),
                $budget['maxPoints'],
                $budget['maxExactHits'],
            );
            $lockedWinners = $lockService->lockedWinners($rows);
        }

        return $this->render('home/index.html.twig', [
            'lockedWinners' => $lockedWinners,
        ]);
    }

    /**
     * Today's matches (in the browser's local day) rendered inside a Turbo Frame.
     */
    #[Route('/home/today-matches', name: 'app_home_today_matches', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function todayMatches(
        Request $request,
        FixtureRepository $fixtureRepository,
        PredictionRepository $predictionRepository,
        FixturePredictionViewService $viewService,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        [$fixtures, $isFallback, $jornadaDate] = $this->resolveFixtures($request, $fixtureRepository, $nowUtc);
        $views = $this->buildViews($fixtures, $user, $nowUtc, $predictionRepository, $viewService);

        return $this->render('home/today_matches.html.twig', [
            'views' => $views,
            'isFallback' => $isFallback,
            'jornadaDate' => $jornadaDate,
            'canParticipate' => $user->isVerified(),
            'paymentValidatedAt' => $user->getPaymentValidatedAt(),
        ]);
    }

    /**
     * Lightweight JSON used by the front-end polling to refresh live score/points.
     */
    #[Route('/home/today-matches/live.json', name: 'app_home_today_matches_live', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function todayMatchesLive(
        Request $request,
        FixtureRepository $fixtureRepository,
        PredictionRepository $predictionRepository,
        FixturePredictionViewService $viewService,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();
        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        [$fixtures] = $this->resolveFixtures($request, $fixtureRepository, $nowUtc);
        $views = $this->buildViews($fixtures, $user, $nowUtc, $predictionRepository, $viewService);

        $payload = array_map(static fn (array $v): array => [
            'id' => $v['id'],
            'statusLabel' => $v['statusLabel'],
            'statusClass' => $v['statusClass'],
            'scoreboardText' => $v['scoreboardText'],
            'pointsText' => $v['pointsText'],
            'pointsClass' => $v['pointsClass'],
        ], $views);

        return $this->json(['fixtures' => $payload]);
    }

    /**
     * Returns [fixtures, isFallback, jornadaDate]. "Today" is the calendar day in the
     * user's timezone (IANA name sent as ?tz=), from local 00:00 to 23:59:59. Each match
     * belongs to the local day it falls on (after-midnight kickoffs included). Falls back
     * to upcoming fixtures if the day is empty.
     *
     * @return array{0: list<Fixture>, 1: bool, 2: \DateTimeImmutable}
     */
    private function resolveFixtures(
        Request $request,
        FixtureRepository $fixtureRepository,
        \DateTimeImmutable $nowUtc,
    ): array {
        $tz = $this->resolveTimezone($request->query->get('tz'));

        $startLocal = $nowUtc->setTimezone($tz)->setTime(0, 0, 0);
        $endLocal = $startLocal->modify('+1 day')->modify('-1 second');

        $utc = new \DateTimeZone('UTC');
        $fixtures = $fixtureRepository->findBetween(
            $startLocal->setTimezone($utc),
            $endLocal->setTimezone($utc),
        );

        if ($fixtures !== []) {
            return [$fixtures, false, $startLocal];
        }

        return [$fixtureRepository->findNextScheduled(self::FALLBACK_LIMIT), true, $startLocal];
    }

    private function resolveTimezone(mixed $tzName): \DateTimeZone
    {
        if (is_string($tzName) && $tzName !== '') {
            try {
                return new \DateTimeZone($tzName);
            } catch (\Exception) {
                // ignore invalid timezone and fall through to default
            }
        }

        return new \DateTimeZone('UTC');
    }

    /**
     * @param list<Fixture> $fixtures
     *
     * @return list<array<string, mixed>>
     */
    private function buildViews(
        array $fixtures,
        User $user,
        \DateTimeImmutable $nowUtc,
        PredictionRepository $predictionRepository,
        FixturePredictionViewService $viewService,
    ): array {
        $paymentValidatedAt = $user->getPaymentValidatedAt();
        $views = [];

        foreach ($fixtures as $fixture) {
            $prediction = $predictionRepository->findOneByUserAndFixture($user, $fixture);

            $views[] = [
                'id' => $fixture->getId(),
                'fixture' => $fixture,
                'prediction' => $prediction,
                'phaseLabel' => $this->phaseLabel($fixture),
                ...$viewService->build($fixture, $prediction, $nowUtc, $paymentValidatedAt),
            ];
        }

        // Partidos no finalizados primero; dentro de cada grupo se conserva el
        // orden por kickoff ascendente (la entrada ya viene así y usort es estable).
        usort($views, static fn (array $a, array $b): int => ($a['state'] === 'finished' ? 1 : 0) <=> ($b['state'] === 'finished' ? 1 : 0));

        return $views;
    }

    /**
     * Label shown in the card header: "Grupo A" for group-stage matches,
     * or just the knockout stage name ("Octavos", "Dieciseisavos", …).
     */
    private function phaseLabel(Fixture $fixture): string
    {
        if ($fixture->isKnockout()) {
            return $fixture->getStageLabel();
        }

        $group = $fixture->getGroup() ?? $fixture->getHomeTeam()?->getGroup();
        $code = $group?->getCode();

        return $code !== null ? 'Grupo '.$code : '-';
    }

    private function scoreboardText(Fixture $fixture, bool $hasScore): ?string
    {
        if (!$hasScore) {
            return null;
        }

        $text = sprintf('%d - %d', $fixture->getHomeScore(), $fixture->getAwayScore());
        if ($fixture->wentToPenalties()) {
            $text .= sprintf(' (%d - %d pen.)', $fixture->getPenaltyHomeScore(), $fixture->getPenaltyAwayScore());
        }

        return $text;
    }
}
