<?php

namespace App\Controller;

use App\Repository\FixtureRepository;
use App\Repository\PredictionRepository;
use App\Service\ScoringService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class LeaderboardController extends AbstractController
{
    #[Route('/leaderboard', name: 'app_leaderboard', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(
        ScoringService $scoringService,
        FixtureRepository $fixtureRepository,
        PredictionRepository $predictionRepository,
    ): Response {
        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $fixturesByGroup = [];
        foreach ($fixtureRepository->findAllOrderedByGroupAndKickoff() as $fixture) {
            $groupCode = $fixture->getGroup()?->getCode() ?? '-';
            $fixturesByGroup[$groupCode][] = $fixture;
        }

        $predictionsByGroup = [];
        foreach ($predictionRepository->findClosedWithFixtureAndUserOrderedForGroups($nowUtc) as $prediction) {
            $groupCode = $prediction->getFixture()?->getGroup()?->getCode() ?? '-';
            $predictionsByGroup[$groupCode][] = $prediction;
        }

        $rows = $this->withSharedPositions($scoringService->leaderboard());
        $weeklyRows = $this->withSharedPositions($scoringService->weeklyLeaderboard($nowUtc, 7));

        return $this->render('leaderboard/index.html.twig', [
            'rows' => $rows,
            'weeklyRows' => $weeklyRows,
            'streakByUser' => $scoringService->activeStreakByUser(),
            'livePointsByUser' => $scoringService->livePointsByUser($nowUtc),
            'nextFixture' => $fixtureRepository->findNextScheduledFixture(),
            'fixturesByGroup' => $fixturesByGroup,
            'predictionsByGroup' => $predictionsByGroup,
        ]);
    }

    /**
     * @param list<array{userId:int, name:string, points:int, exactHits:int}> $rows
     *
     * @return list<array{userId:int, name:string, points:int, exactHits:int, rank:int}>
     */
    private function withSharedPositions(array $rows): array
    {
        $rankedRows = [];
        $currentRank = 0;
        $previousPoints = null;
        $previousExactHits = null;

        foreach ($rows as $index => $row) {
            if ($row['points'] !== $previousPoints || $row['exactHits'] !== $previousExactHits) {
                $currentRank = $index + 1;
                $previousPoints = $row['points'];
                $previousExactHits = $row['exactHits'];
            }

            $row['rank'] = $currentRank;
            $rankedRows[] = $row;
        }

        return $rankedRows;
    }
}
