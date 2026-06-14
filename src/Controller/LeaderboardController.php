<?php

namespace App\Controller;

use App\Entity\Fixture;
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
        $groupNextKickoff = [];
        foreach ($fixtureRepository->findAllOrderedByGroupAndKickoff() as $fixture) {
            $groupCode = $fixture->getGroup()?->getCode() ?? '-';
            $fixturesByGroup[$groupCode][] = $fixture;

            if ($fixture->getStatus() === \App\Entity\Fixture::STATUS_SCHEDULED && !isset($groupNextKickoff[$groupCode])) {
                $groupNextKickoff[$groupCode] = $fixture->getKickoffAt()->getTimestamp();
            }
        }

        uksort($fixturesByGroup, function ($codeA, $codeB) use ($groupNextKickoff) {
            $tsA = $groupNextKickoff[$codeA] ?? PHP_INT_MAX;
            $tsB = $groupNextKickoff[$codeB] ?? PHP_INT_MAX;

            if ($tsA === $tsB) {
                return $codeA <=> $codeB;
            }
            return $tsA <=> $tsB;
        });

        $predictionsByGroup = [];
        foreach ($predictionRepository->findClosedWithFixtureAndUserOrderedForGroups($nowUtc) as $prediction) {
            $fixture = $prediction->getFixture();
            if (!$fixture) {
                continue;
            }
            $groupCode = $fixture->getGroup()?->getCode() ?? '-';
            $fixtureId = $fixture->getId();
            if (!isset($predictionsByGroup[$groupCode][$fixtureId])) {
                $predictionsByGroup[$groupCode][$fixtureId] = [
                    'fixture' => $fixture,
                    'predictions' => [],
                ];
            }
            $predictionsByGroup[$groupCode][$fixtureId]['predictions'][] = $prediction;
        }

        // Sort fixtures under each group code:
        // - Scheduled matches first (kickoffAt ASC, i.e., soonest first)
        // - Finished matches last (kickoffAt DESC, i.e., most recently played first)
        foreach ($predictionsByGroup as $groupCode => &$fixtures) {
            uasort($fixtures, function ($a, $b) {
                $fixA = $a['fixture'];
                $fixB = $b['fixture'];

                if ($fixA->getStatus() !== $fixB->getStatus()) {
                    return $fixA->getStatus() === \App\Entity\Fixture::STATUS_SCHEDULED ? -1 : 1;
                }

                $timeA = $fixA->getKickoffAt()->getTimestamp();
                $timeB = $fixB->getKickoffAt()->getTimestamp();

                if ($fixA->getStatus() === \App\Entity\Fixture::STATUS_SCHEDULED) {
                    return $timeA <=> $timeB;
                } else {
                    return $timeB <=> $timeA;
                }
            });
        }
        unset($fixtures);

        $rows = $this->withSharedPositions($scoringService->leaderboard());
        $remainingCount = $fixtureRepository->count(['status' => Fixture::STATUS_SCHEDULED]);

        return $this->render('leaderboard/index.html.twig', [
            'rows' => $rows,
            'remainingCount' => $remainingCount,
            'streakByUser' => $scoringService->activeStreakByUser(),
            'livePointsByUser' => $scoringService->livePointsByUser($nowUtc),
            'nextFixture' => $fixtureRepository->findNextScheduledFixture(),
            'fixturesByGroup' => $fixturesByGroup,
            'predictionsByGroup' => $predictionsByGroup,
            'scoringService' => $scoringService,
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
