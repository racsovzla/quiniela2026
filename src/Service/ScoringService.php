<?php

namespace App\Service;

use App\Entity\Fixture;
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

    public function calculateRegularPoints(Prediction $prediction): int
    {
        return $this->calculateRegularPointsInternal($prediction, false);
    }

    public function calculatePenaltyPoints(Prediction $prediction): int
    {
        return $this->calculatePenaltyPointsInternal($prediction, false);
    }

    /**
     * Pure scoring for UI preview (no payment/status guards).
     */
    public function pointsForPrediction(Prediction $prediction): int
    {
        $fixture = $prediction->getFixture();
        if (!$fixture || !$fixture->hasFinalScore()) {
            return 0;
        }

        $realHome = $fixture->getHomeScore();
        $realAway = $fixture->getAwayScore();
        if ($realHome === null || $realAway === null) {
            return 0;
        }

        return $this->pointsForPredictionScores(
            $prediction,
            $fixture,
            $realHome,
            $realAway,
        );
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
        return $this->calculateRegularPointsInternal($prediction, $allowScheduledWithScore)
            + $this->calculatePenaltyPointsInternal($prediction, $allowScheduledWithScore);
    }

    private function calculateRegularPointsInternal(Prediction $prediction, bool $allowScheduledWithScore): int
    {
        if (!$this->isEligibleForScoring($prediction, $allowScheduledWithScore)) {
            return 0;
        }

        $fixture = $prediction->getFixture();
        $realHome = $fixture?->getHomeScore();
        $realAway = $fixture?->getAwayScore();
        if ($realHome === null || $realAway === null) {
            return 0;
        }

        return $this->pointsForScores(
            $prediction->getPredictedHomeScore(),
            $prediction->getPredictedAwayScore(),
            $realHome,
            $realAway,
        );
    }

    private function calculatePenaltyPointsInternal(Prediction $prediction, bool $allowScheduledWithScore): int
    {
        if (!$this->isEligibleForScoring($prediction, $allowScheduledWithScore)) {
            return 0;
        }

        $fixture = $prediction->getFixture();
        if (!$fixture) {
            return 0;
        }

        $realHome = $fixture->getHomeScore();
        $realAway = $fixture->getAwayScore();
        if ($realHome === null || $realAway === null) {
            return 0;
        }

        return $this->penaltyPointsForPrediction($prediction, $fixture);
    }

    private function isEligibleForScoring(Prediction $prediction, bool $allowScheduledWithScore): bool
    {
        $user = $prediction->getUser();
        if (!$user) {
            return false;
        }

        $paymentValidatedAt = $user->getPaymentValidatedAt();
        if (null === $paymentValidatedAt) {
            return false;
        }

        $fixture = $prediction->getFixture();

        if (!$fixture || !$fixture->hasFinalScore()) {
            return false;
        }

        if ($fixture->getKickoffAt() < $paymentValidatedAt) {
            return false;
        }

        if (!$allowScheduledWithScore && $fixture->getStatus() !== Fixture::STATUS_FINISHED) {
            return false;
        }

        return true;
    }

    /**
     * Pure 3/1/0 rule for a prediction vs a score, without payment/status guards.
     * Useful to display potential (provisional/final) points on the home page.
     */
    public function pointsForScores(int $predHome, int $predAway, int $realHome, int $realAway): int
    {
        if ($predHome === $realHome && $predAway === $realAway) {
            return 3;
        }

        return ($predHome <=> $predAway) === ($realHome <=> $realAway) ? 1 : 0;
    }

    private function pointsForPredictionScores(
        Prediction $prediction,
        Fixture $fixture,
        int $realHome,
        int $realAway,
    ): int {
        $regular = $this->pointsForScores(
            $prediction->getPredictedHomeScore(),
            $prediction->getPredictedAwayScore(),
            $realHome,
            $realAway,
        );

        return $regular + $this->penaltyPointsForPrediction($prediction, $fixture);
    }

    private function penaltyPointsForPrediction(Prediction $prediction, Fixture $fixture): int
    {
        if (!$fixture->isKnockout()) {
            return 0;
        }

        if ($prediction->getPredictedHomeScore() !== $prediction->getPredictedAwayScore()) {
            return 0;
        }

        if (!$fixture->wentToPenalties()) {
            return 0;
        }

        if (!$prediction->hasPenaltyPrediction()) {
            return 0;
        }

        $realPenHome = $fixture->getPenaltyHomeScore();
        $realPenAway = $fixture->getPenaltyAwayScore();
        if ($realPenHome === null || $realPenAway === null) {
            return 0;
        }

        return $this->pointsForScores(
            $prediction->getPredictedPenaltyHomeScore(),
            $prediction->getPredictedPenaltyAwayScore(),
            $realPenHome,
            $realPenAway,
        );
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
            if ($this->calculateRegularPoints($prediction) === 3) {
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
