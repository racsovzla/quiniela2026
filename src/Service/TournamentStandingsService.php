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
     * @return array<string, list<array{code: string, pj: int, pg: int, pe: int, pp: int, gf: int, gc: int, dg: int, pts: int}>>
     */
    public function standingsByGroup(array $rows): array
    {
        /** @var array<string, array<string, array<string, int|string>>> $tables */
        $tables = [];

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
                continue;
            }

            $this->applyResult($tables[$group][$home], $scores['home'], $scores['away']);
            $this->applyResult($tables[$group][$away], $scores['away'], $scores['home']);
        }

        ksort($tables);

        $result = [];
        foreach ($tables as $group => $teams) {
            $list = array_values($teams);
            usort($list, static function (array $a, array $b): int {
                return [$b['pts'], $b['dg'], $b['gf']] <=> [$a['pts'], $a['dg'], $a['gf']]
                    ?: strcmp((string) $a['code'], (string) $b['code']);
            });
            $result[$group] = $list;
        }

        return $result;
    }

    /**
     * Knockout fixtures grouped by stage key (r32, r16, qf, sf, final, third).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, list<array{home: ?string, away: ?string, homeLabel: ?string, awayLabel: ?string, homeScore: ?int, awayScore: ?int, finished: bool, kickoffIso: ?string}>>
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

            $knockout[$stage][] = [
                'home' => $this->client->teamCode($row, 'Home'),
                'away' => $this->client->teamCode($row, 'Away'),
                'homeLabel' => $this->client->teamPlaceholder($row, 'Home'),
                'awayLabel' => $this->client->teamPlaceholder($row, 'Away'),
                'homeScore' => $scores['home'] ?? null,
                'awayScore' => $scores['away'] ?? null,
                'finished' => $this->client->isFinished($row),
                'kickoffIso' => $this->client->kickoffIso($row),
            ];
        }

        return $knockout;
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
