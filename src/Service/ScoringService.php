<?php

namespace App\Service;

use App\Entity\Prediction;
use App\Repository\PredictionRepository;

class ScoringService
{
    public function __construct(private readonly PredictionRepository $predictionRepository)
    {
    }

    public function calculatePoints(Prediction $prediction): int
    {
        return $this->calculatePointsInternal($prediction, false);
    }

    public function calculateLivePoints(Prediction $prediction): int
    {
        return $this->calculatePointsInternal($prediction, true);
    }

    /**
     * @return list<array{name:string, points:int, exactHits:int}>
     */
    public function weeklyLeaderboard(\DateTimeImmutable $nowUtc, int $days = 7): array
    {
        $fromUtc = $nowUtc->modify(sprintf('-%d days', max(1, $days)));

        return $this->buildRows($this->predictionRepository->findFinishedBetweenKickoff($fromUtc, $nowUtc));
    }

    /**
     * @return array<int, int> userId => consecutive finished matches with at least 1 point
     */
    public function activeStreakByUser(): array
    {
        $streaks = [];
        $streakClosed = [];

        foreach ($this->predictionRepository->findFinishedOrderedByUserAndKickoffDesc() as $prediction) {
            $userId = $prediction->getUser()?->getId();
            if (null === $userId) {
                continue;
            }

            if (!isset($streaks[$userId])) {
                $streaks[$userId] = 0;
                $streakClosed[$userId] = false;
            }

            if ($streakClosed[$userId]) {
                continue;
            }

            if ($this->calculatePoints($prediction) > 0) {
                $streaks[$userId]++;

                continue;
            }

            $streakClosed[$userId] = true;
        }

        return $streaks;
    }

    private function calculatePointsInternal(Prediction $prediction, bool $allowScheduledWithScore): int
    {
        $user = $prediction->getUser();
        if (!$user) {
            return 0;
        }

        $paymentValidatedAt = $user->getPaymentValidatedAt();
        if (null === $paymentValidatedAt) {
            return 0;
        }

        $fixture = $prediction->getFixture();

        if (!$fixture || !$fixture->hasFinalScore()) {
            return 0;
        }

        if ($fixture->getKickoffAt() < $paymentValidatedAt) {
            return 0;
        }

        if (!$allowScheduledWithScore && $fixture->getStatus() !== \App\Entity\Fixture::STATUS_FINISHED) {
            return 0;
        }

        $predHome = $prediction->getPredictedHomeScore();
        $predAway = $prediction->getPredictedAwayScore();
        $realHome = $fixture->getHomeScore();
        $realAway = $fixture->getAwayScore();

        if ($realHome === null || $realAway === null) {
            return 0;
        }

        if ($predHome === $realHome && $predAway === $realAway) {
            return 3;
        }

        $predResult = $predHome <=> $predAway;
        $realResult = $realHome <=> $realAway;

        return $predResult === $realResult ? 1 : 0;
    }

    /**
     * @return list<array{name:string, points:int, exactHits:int}>
     */
    public function leaderboard(): array
    {
        return $this->buildRows($this->predictionRepository->findAll());
    }

    /**
     * @return array<string, list<array{name:string, points:int, exactHits:int}>>
     */
    public function leaderboardByGroup(): array
    {
        $predictions = $this->predictionRepository->findAll();
        $byGroupPredictions = [];

        foreach ($predictions as $prediction) {
            $fixture = $prediction->getFixture();
            if (!$fixture) {
                continue;
            }

            $group = $fixture->getGroup() ?? $fixture->getHomeTeam()?->getGroup();
            if (!$group) {
                continue;
            }

            $groupCode = $group->getCode();
            if (!isset($byGroupPredictions[$groupCode])) {
                $byGroupPredictions[$groupCode] = [];
            }

            $byGroupPredictions[$groupCode][] = $prediction;
        }

        ksort($byGroupPredictions);

        $rows = [];
        foreach ($byGroupPredictions as $groupCode => $groupPredictions) {
            $rows[$groupCode] = $this->buildRows($groupPredictions);
        }

        return $rows;
    }

    /**
     * @return array<int, int> userId => livePoints
     */
    public function livePointsByUser(\DateTimeImmutable $nowUtc): array
    {
        $livePointsByUser = [];

        foreach ($this->predictionRepository->findInProgressWithLoadedScoresForLivePoints($nowUtc) as $prediction) {
            $userId = $prediction->getUser()?->getId();
            if (null === $userId) {
                continue;
            }

            $livePointsByUser[$userId] = ($livePointsByUser[$userId] ?? 0) + $this->calculateLivePoints($prediction);
        }

        return $livePointsByUser;
    }

    /**
     * @param list<Prediction> $predictions
     *
     * @return list<array{userId:int, name:string, points:int, exactHits:int}>
     */
    private function buildRows(array $predictions): array
    {
        $rows = [];

        foreach ($predictions as $prediction) {
            $user = $prediction->getUser();
            if (!$user) {
                continue;
            }

            $key = (string) $user->getId();
            if (!isset($rows[$key])) {
                $rows[$key] = [
                    'userId' => $user->getId(),
                    'name' => $user->getName(),
                    'points' => 0,
                    'exactHits' => 0,
                ];
            }

            $points = $this->calculatePoints($prediction);
            $rows[$key]['points'] += $points;
            if ($points === 3) {
                $rows[$key]['exactHits']++;
            }
        }

        $leaderboard = array_values($rows);
        usort($leaderboard, static function (array $a, array $b): int {
            $byPoints = $b['points'] <=> $a['points'];
            if ($byPoints !== 0) {
                return $byPoints;
            }

            $byExact = $b['exactHits'] <=> $a['exactHits'];
            if ($byExact !== 0) {
                return $byExact;
            }

            return $a['userId'] <=> $b['userId'];
        });

        return $leaderboard;
    }
}
