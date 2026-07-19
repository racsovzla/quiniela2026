<?php

namespace App\Service;

/**
 * Mathematical clinch of quiniela places 1–3 using remaining point/exact budgets.
 * Tie-break matches the leaderboard: points, then exact hits (shared rank when equal).
 */
class LeaderboardLockService
{
    /**
     * @param list<array{userId: int, name: string, points: int, exactHits: int}> $rows
     *
     * @return list<array{userId: int, name: string, points: int, exactHits: int, rank: int}>
     */
    public function withSharedPositions(array $rows): array
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

    /**
     * @param list<array{userId: int, name: string, points: int, exactHits: int, rank: int}> $rows
     *
     * @return list<array{userId: int, name: string, points: int, exactHits: int, rank: int, lockedPlace: ?int}>
     */
    public function annotate(array $rows, int $maxRemainingPoints, int $maxRemainingExactHits): array
    {
        $annotated = [];

        foreach ($rows as $row) {
            $best = $this->bestRank($row, $rows, $maxRemainingPoints, $maxRemainingExactHits);
            $worst = $this->worstRank($row, $rows, $maxRemainingPoints, $maxRemainingExactHits);
            $locked = ($best === $worst && $best >= 1 && $best <= 3) ? $best : null;

            $row['lockedPlace'] = $locked;
            $annotated[] = $row;
        }

        return $annotated;
    }

    /**
     * @param list<array{userId: int, name: string, points: int, exactHits: int, rank: int, lockedPlace: ?int}> $rows
     *
     * @return array<int, list<array{userId: int, name: string, points: int, exactHits: int, rank: int, lockedPlace: ?int}>>
     */
    public function lockedWinners(array $rows): array
    {
        $winners = [];

        foreach ($rows as $row) {
            $place = $row['lockedPlace'] ?? null;
            if ($place === null) {
                continue;
            }

            $winners[$place][] = $row;
        }

        ksort($winners);

        return $winners;
    }

    /**
     * @param array{userId: int, points: int, exactHits: int} $user
     * @param list<array{userId: int, points: int, exactHits: int}> $rows
     */
    private function bestRank(array $user, array $rows, int $maxPts, int $maxExact): int
    {
        $ahead = 0;

        foreach ($rows as $other) {
            if ($other['userId'] === $user['userId']) {
                continue;
            }

            if ($this->strictlyBeats(
                $other['points'],
                $other['exactHits'],
                $user['points'] + $maxPts,
                $user['exactHits'] + $maxExact,
            )) {
                ++$ahead;
            }
        }

        return $ahead + 1;
    }

    /**
     * @param array{userId: int, points: int, exactHits: int} $user
     * @param list<array{userId: int, points: int, exactHits: int}> $rows
     */
    private function worstRank(array $user, array $rows, int $maxPts, int $maxExact): int
    {
        $ahead = 0;

        foreach ($rows as $other) {
            if ($other['userId'] === $user['userId']) {
                continue;
            }

            if ($this->strictlyBeats(
                $other['points'] + $maxPts,
                $other['exactHits'] + $maxExact,
                $user['points'],
                $user['exactHits'],
            )) {
                ++$ahead;
            }
        }

        return $ahead + 1;
    }

    private function strictlyBeats(int $pointsA, int $exactA, int $pointsB, int $exactB): bool
    {
        if ($pointsA !== $pointsB) {
            return $pointsA > $pointsB;
        }

        return $exactA > $exactB;
    }
}
