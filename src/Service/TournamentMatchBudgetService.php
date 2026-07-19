<?php

namespace App\Service;

use App\Entity\Fixture;
use App\Repository\FixtureRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Remaining-match budget from the FIFA calendar (same source as /fases),
 * with a DB fallback when the API is unavailable.
 */
class TournamentMatchBudgetService
{
    public function __construct(
        private readonly FifaCalendarClient $fifaCalendarClient,
        private readonly FixtureRepository $fixtureRepository,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return array{total: int, remaining: int, maxPoints: int, maxExactHits: int, fromFifa: bool}
     */
    public function current(): array
    {
        try {
            $rows = $this->cache->get('fifa_all_matches', function (ItemInterface $item): array {
                $item->expiresAfter(120);

                return $this->fifaCalendarClient->fetchAllMatches();
            });

            return $this->fromFifaRows($rows);
        } catch (\Throwable) {
            return $this->fromDatabase();
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array{total: int, remaining: int, maxPoints: int, maxExactHits: int, fromFifa: bool}
     */
    public function fromFifaRows(array $rows): array
    {
        $remaining = 0;
        $maxPoints = 0;
        $maxExactHits = 0;

        foreach ($rows as $row) {
            if ($this->fifaCalendarClient->isFinished($row)) {
                continue;
            }

            ++$remaining;
            $stage = $this->fifaCalendarClient->extractStageKey($row);
            // Knockout: 3 regular + 3 pens; group: 3. Exact hits only count regular 3s.
            $maxPoints += $stage === Fixture::STAGE_GROUP ? 3 : 6;
            ++$maxExactHits;
        }

        return [
            'total' => \count($rows),
            'remaining' => $remaining,
            'maxPoints' => $maxPoints,
            'maxExactHits' => $maxExactHits,
            'fromFifa' => true,
        ];
    }

    /**
     * @return array{total: int, remaining: int, maxPoints: int, maxExactHits: int, fromFifa: bool}
     */
    private function fromDatabase(): array
    {
        $remaining = $this->fixtureRepository->count(['status' => Fixture::STATUS_SCHEDULED]);
        $finished = $this->fixtureRepository->count(['status' => Fixture::STATUS_FINISHED]);

        return [
            'total' => $remaining + $finished,
            'remaining' => $remaining,
            'maxPoints' => $remaining * 6,
            'maxExactHits' => $remaining,
            'fromFifa' => false,
        ];
    }
}
