<?php

namespace App\Controller;

use App\Entity\Fixture;
use App\Entity\Prediction;
use App\Entity\User;
use App\Repository\FixtureRepository;
use App\Repository\PredictionRepository;
use App\Service\PredictionWindowService;
use App\Service\ScoringService;
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

    /**
     * The "football day" runs from this local hour to the same hour next day, so
     * after-midnight kickoffs stay grouped with the matchday they belong to.
     */
    private const MORNING_CUTOFF_HOUR = 8;

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('home/index.html.twig');
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
        PredictionWindowService $windowService,
        ScoringService $scoringService,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        [$fixtures, $isFallback, $jornadaDate] = $this->resolveFixtures($request, $fixtureRepository, $nowUtc);
        $views = $this->buildViews($fixtures, $user, $nowUtc, $predictionRepository, $windowService, $scoringService);

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
        PredictionWindowService $windowService,
        ScoringService $scoringService,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();
        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        [$fixtures] = $this->resolveFixtures($request, $fixtureRepository, $nowUtc);
        $views = $this->buildViews($fixtures, $user, $nowUtc, $predictionRepository, $windowService, $scoringService);

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
     * Returns [fixtures, isFallback, jornadaDate]. "Today" is the matchday in the user's
     * timezone (IANA name sent as ?tz=), defined as the window [today 08:00, tomorrow
     * 08:00) local: this keeps after-midnight kickoffs (madrugada) grouped with the day
     * they belong to, so users don't miss loading their predictions. Falls back to
     * upcoming fixtures if the window is empty.
     *
     * @return array{0: list<Fixture>, 1: bool, 2: \DateTimeImmutable}
     */
    private function resolveFixtures(
        Request $request,
        FixtureRepository $fixtureRepository,
        \DateTimeImmutable $nowUtc,
    ): array {
        $tz = $this->resolveTimezone($request->query->get('tz'));
        $nowLocal = $nowUtc->setTimezone($tz);

        // Before the cutoff hour we are still inside the previous day's matchday.
        $anchor = (int) $nowLocal->format('G') < self::MORNING_CUTOFF_HOUR
            ? $nowLocal->modify('-1 day')
            : $nowLocal;

        $startLocal = $anchor->setTime(self::MORNING_CUTOFF_HOUR, 0, 0);
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
        PredictionWindowService $windowService,
        ScoringService $scoringService,
    ): array {
        $paymentValidatedAt = $user->getPaymentValidatedAt();
        $views = [];

        foreach ($fixtures as $fixture) {
            $prediction = $predictionRepository->findOneByUserAndFixture($user, $fixture);
            $hasScore = $fixture->hasFinalScore();
            $kickoff = $fixture->getKickoffAt();
            $finished = $fixture->getStatus() === Fixture::STATUS_FINISHED;
            $isLive = !$finished && $hasScore && $kickoff <= $nowUtc;
            $canEdit = $windowService->canEditAt($fixture, $nowUtc);

            $state = match (true) {
                $finished => 'finished',
                $isLive => 'live',
                $canEdit => 'open',
                default => 'closed',
            };

            [$statusLabel, $statusClass] = match ($state) {
                'finished' => ['Finalizado', 'text-bg-secondary'],
                'live' => ['EN VIVO', 'text-bg-danger'],
                'open' => ['Abierta', 'text-bg-success'],
                default => ['Cerrada', 'text-bg-warning'],
            };

            $views[] = [
                'id' => $fixture->getId(),
                'fixture' => $fixture,
                'prediction' => $prediction,
                'groupCode' => $this->groupCode($fixture),
                'state' => $state,
                'canEdit' => $canEdit,
                'deadlineIso' => $windowService->deadline($fixture)->format('c'),
                'statusLabel' => $statusLabel,
                'statusClass' => $statusClass,
                'scoreboardText' => $hasScore ? sprintf('%d - %d', $fixture->getHomeScore(), $fixture->getAwayScore()) : null,
                ...$this->pointsView($fixture, $prediction, $state, $hasScore, $paymentValidatedAt, $scoringService),
            ];
        }

        return $views;
    }

    /**
     * @return array{pointsText: ?string, pointsClass: string, countsNote: ?string}
     */
    private function pointsView(
        Fixture $fixture,
        ?Prediction $prediction,
        string $state,
        bool $hasScore,
        ?\DateTimeImmutable $paymentValidatedAt,
        ScoringService $scoringService,
    ): array {
        if ($state !== 'live' && $state !== 'finished') {
            return ['pointsText' => null, 'pointsClass' => '', 'countsNote' => null];
        }

        if (!$prediction || !$hasScore) {
            return ['pointsText' => 'No cargaste predicción', 'pointsClass' => 'text-body-secondary', 'countsNote' => null];
        }

        $points = $scoringService->pointsForScores(
            $prediction->getPredictedHomeScore(),
            $prediction->getPredictedAwayScore(),
            $fixture->getHomeScore(),
            $fixture->getAwayScore(),
        );

        if ($state === 'finished') {
            $pointsText = sprintf('🏆 Obtuviste: %d %s', $points, $points === 1 ? 'punto' : 'puntos');
            $pointsClass = 'text-success fw-semibold';
        } else {
            $pointsText = sprintf('⚡ Puntos provisionales: +%d (puede cambiar)', $points);
            $pointsClass = 'text-info fw-semibold';
        }

        $counts = $paymentValidatedAt !== null && $fixture->getKickoffAt() >= $paymentValidatedAt;

        return [
            'pointsText' => $pointsText,
            'pointsClass' => $pointsClass,
            'countsNote' => $counts ? null : 'No suma todavía: pago pendiente de validación.',
        ];
    }

    private function groupCode(Fixture $fixture): string
    {
        $group = $fixture->getGroup() ?? $fixture->getHomeTeam()?->getGroup();

        return $group?->getCode() ?? '-';
    }
}
