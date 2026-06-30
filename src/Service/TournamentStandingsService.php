<?php

namespace App\Service;

/**
 * Builds, from the raw FIFA match feed, the data the "Fases" page needs:
 *  - group standings tables (computed from results), and
 *  - knockout fixtures grouped by stage.
 *
 * Stateless and DB-independent: everything comes from the API rows.
 */
class TournamentStandingsService
{
    public function __construct(
        private readonly FifaCalendarClient $client,
    ) {
    }

    /**
     * Standings per group code (A, B, …), each ordered by Pts, goal diff, goals for.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, list<array{code: string, pj: int, pg: int, pe: int, pp: int, gf: int, gc: int, dg: int, pts: int, status: ?string}>>
     */
    public function standingsByGroup(array $rows): array
    {
        /** @var array<string, array<string, array<string, int|string>>> $tables */
        $tables = [];
        /** @var array<string, list<array{home: string, away: string}>> $remainingMatches */
        $remainingMatches = [];

        foreach ($rows as $row) {
            if ($this->client->extractStageKey($row) !== 'group') {
                continue;
            }

            $group = $this->client->extractGroupCode($row);
            $home = $this->client->teamCode($row, 'Home');
            $away = $this->client->teamCode($row, 'Away');

            if ($group === null || $home === null || $away === null) {
                continue;
            }

            // Make sure both teams appear even before they have played.
            $tables[$group] ??= [];
            $tables[$group][$home] ??= $this->emptyRow($home);
            $tables[$group][$away] ??= $this->emptyRow($away);

            $scores = $this->client->extractScores($row);
            if (!$this->client->isFinished($row) || $scores === null) {
                $remainingMatches[$group][] = ['home' => $home, 'away' => $away];
                continue;
            }

            $this->applyResult($tables[$group][$home], $scores['home'], $scores['away']);
            $this->applyResult($tables[$group][$away], $scores['away'], $scores['home']);
        }

        ksort($tables);

        $result = [];
        foreach ($tables as $group => $teams) {
            $statuses = $this->qualificationStatuses($teams, $remainingMatches[$group] ?? []);
            $list = array_values($teams);
            usort($list, static function (array $a, array $b): int {
                return self::compareTeams($a, $b);
            });

            foreach ($list as &$team) {
                $team['status'] = $statuses[(string) $team['code']] ?? null;
            }
            unset($team);

            $result[$group] = $list;
        }

        return $result;
    }

    /**
     * Knockout fixtures grouped by stage key (r32, r16, qf, sf, final, third).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, list<array{home: ?string, away: ?string, homeLabel: ?string, awayLabel: ?string, homeScore: ?int, awayScore: ?int, penaltyHomeScore: ?int, penaltyAwayScore: ?int, finished: bool, kickoffIso: ?string}>>
     */
    public function matchesByStage(array $rows): array
    {
        $knockout = ['r32' => [], 'r16' => [], 'qf' => [], 'sf' => [], 'final' => [], 'third' => []];

        foreach ($rows as $row) {
            $stage = $this->client->extractStageKey($row);
            if (!isset($knockout[$stage])) {
                continue;
            }

            $scores = $this->client->extractScores($row);
            $penalties = $this->client->extractPenaltyScores($row);

            $knockout[$stage][] = [
                'home' => $this->client->teamCode($row, 'Home'),
                'away' => $this->client->teamCode($row, 'Away'),
                'homeLabel' => $this->client->teamPlaceholder($row, 'Home'),
                'awayLabel' => $this->client->teamPlaceholder($row, 'Away'),
                'homeScore' => $scores['home'] ?? null,
                'awayScore' => $scores['away'] ?? null,
                'penaltyHomeScore' => $penalties['home'] ?? null,
                'penaltyAwayScore' => $penalties['away'] ?? null,
                'finished' => $this->client->isFinished($row),
                'kickoffIso' => $this->client->kickoffIso($row),
            ];
        }

        return $knockout;
    }

    /**
     * Top 2 per group qualify for the round of 32. Brute-forces every remaining
     * result (win/draw/loss with 1-0 / 0-0 scorelines) using the same tiebreakers
     * as the live table: points, goal difference, goals for.
     *
     * @param array<string, array<string, int|string>> $teams
     * @param list<array{home: string, away: string}> $remainingMatches
     *
     * @return array<string, ?string> code => qualified|eliminated|null
     */
    private function qualificationStatuses(array $teams, array $remainingMatches): array
    {
        $teamCodes = array_keys($teams);
        if ($teamCodes === []) {
            return [];
        }

        $remainingMatches = $this->dedupeRemainingMatches($remainingMatches);
        $statuses = [];

        if ($remainingMatches === []) {
            $ranked = array_values($teams);
            usort($ranked, static fn (array $a, array $b): int => self::compareTeams($a, $b));
            foreach ($ranked as $index => $team) {
                $statuses[(string) $team['code']] = $index < 2 ? 'qualified' : 'eliminated';
            }

            return $statuses;
        }

        $minRank = array_fill_keys($teamCodes, \PHP_INT_MAX);
        $maxRank = array_fill_keys($teamCodes, 0);

        foreach ($this->enumerateOutcomes(\count($remainingMatches)) as $outcomes) {
            $simulated = $this->cloneTeamStats($teams);
            foreach ($outcomes as $index => $outcome) {
                $match = $remainingMatches[$index];
                $this->applySimulatedOutcome(
                    $simulated,
                    $match['home'],
                    $match['away'],
                    $outcome,
                );
            }

            $ranks = $this->ranksForTeams($simulated);
            foreach ($teamCodes as $code) {
                $rank = $ranks[$code];
                $minRank[$code] = min($minRank[$code], $rank);
                $maxRank[$code] = max($maxRank[$code], $rank);
            }
        }

        foreach ($teamCodes as $code) {
            if ($maxRank[$code] <= 2) {
                $statuses[$code] = 'qualified';
            } elseif ($minRank[$code] > 2) {
                $statuses[$code] = 'eliminated';
            } else {
                $statuses[$code] = null;
            }
        }

        return $statuses;
    }

    /**
     * @param list<array{home: string, away: string}> $remainingMatches
     *
     * @return list<array{home: string, away: string}>
     */
    private function dedupeRemainingMatches(array $remainingMatches): array
    {
        $seen = [];
        $deduped = [];

        foreach ($remainingMatches as $match) {
            $pair = [$match['home'], $match['away']];
            sort($pair);
            $key = implode('_', $pair);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduped[] = $match;
        }

        return $deduped;
    }

    /**
     * @param array<string, array<string, int|string>> $teams
     *
     * @return array<string, array<string, int|string>>
     */
    private function cloneTeamStats(array $teams): array
    {
        $copy = [];
        foreach ($teams as $code => $row) {
            $copy[$code] = $row;
        }

        return $copy;
    }

    /**
     * @return \Generator<int, list<int>>
     */
    private function enumerateOutcomes(int $matchCount, int $index = 0, array $current = []): \Generator
    {
        if ($index === $matchCount) {
            yield $current;

            return;
        }

        foreach ([0, 1, 2] as $outcome) {
            yield from $this->enumerateOutcomes($matchCount, $index + 1, [...$current, $outcome]);
        }
    }

    /**
     * @param array<string, array<string, int|string>> $teams
     */
    private function applySimulatedOutcome(array &$teams, string $home, string $away, int $outcome): void
    {
        if ($outcome === 0) {
            $this->applyResult($teams[$home], 1, 0);
            $this->applyResult($teams[$away], 0, 1);

            return;
        }

        if ($outcome === 1) {
            $this->applyResult($teams[$home], 0, 0);
            $this->applyResult($teams[$away], 0, 0);

            return;
        }

        $this->applyResult($teams[$home], 0, 1);
        $this->applyResult($teams[$away], 1, 0);
    }

    /**
     * @param array<string, array<string, int|string>> $teams
     *
     * @return array<string, int>
     */
    private function ranksForTeams(array $teams): array
    {
        $list = array_values($teams);
        usort($list, static fn (array $a, array $b): int => self::compareTeams($a, $b));

        $ranks = [];
        foreach ($list as $index => $team) {
            $ranks[(string) $team['code']] = $index + 1;
        }

        return $ranks;
    }

    /**
     * @param array<string, int|string> $a
     * @param array<string, int|string> $b
     */
    private static function compareTeams(array $a, array $b): int
    {
        return [$b['pts'], $b['dg'], $b['gf']] <=> [$a['pts'], $a['dg'], $a['gf']]
            ?: strcmp((string) $a['code'], (string) $b['code']);
    }

    /**
     * @return array{code: string, pj: int, pg: int, pe: int, pp: int, gf: int, gc: int, dg: int, pts: int}
     */
    private function emptyRow(string $code): array
    {
        return ['code' => $code, 'pj' => 0, 'pg' => 0, 'pe' => 0, 'pp' => 0, 'gf' => 0, 'gc' => 0, 'dg' => 0, 'pts' => 0];
    }

    /**
     * @param array<string, int|string> $row
     */
    private function applyResult(array &$row, int $for, int $against): void
    {
        ++$row['pj'];
        $row['gf'] += $for;
        $row['gc'] += $against;
        $row['dg'] = $row['gf'] - $row['gc'];

        if ($for > $against) {
            ++$row['pg'];
            $row['pts'] += 3;
        } elseif ($for === $against) {
            ++$row['pe'];
            ++$row['pts'];
        } else {
            ++$row['pp'];
        }
    }
}
