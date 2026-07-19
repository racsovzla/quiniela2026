<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class FifaCalendarClient
{
    public const GROUP_STAGE_API_URL = 'https://api.fifa.com/api/v3/calendar/matches?language=en&idCompetition=17&idSeason=285023&idStage=289273&count=400';

    public const ALL_MATCHES_API_URL = 'https://api.fifa.com/api/v3/calendar/matches?language=en&idCompetition=17&idSeason=285023&count=500';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws \RuntimeException
     */
    public function fetchGroupStageMatches(): array
    {
        return $this->fetchResults(self::GROUP_STAGE_API_URL);
    }

    /**
     * All matches of the competition/season across every stage (group stage now, plus
     * knockout once FIFA publishes it). Same payload shape as the group-stage feed.
     *
     * @return list<array<string, mixed>>
     *
     * @throws \RuntimeException
     */
    public function fetchAllMatches(): array
    {
        return $this->fetchResults(self::ALL_MATCHES_API_URL);
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws \RuntimeException
     */
    private function fetchResults(string $url): array
    {
        try {
            $response = $this->httpClient->request('GET', $url);
            $payload = $response->toArray();
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Could not fetch FIFA API: '.$exception->getMessage(), 0, $exception);
        }

        if (!isset($payload['Results']) || !is_array($payload['Results'])) {
            throw new \RuntimeException('Unexpected FIFA API payload. Missing Results array.');
        }

        return $payload['Results'];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, array<string, mixed>>
     */
    public function indexByTeamCodes(array $rows): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            $homeCode = $row['Home']['Abbreviation'] ?? null;
            $awayCode = $row['Away']['Abbreviation'] ?? null;

            if (!is_string($homeCode) || !is_string($awayCode)) {
                continue;
            }

            $key = $this->matchKey(strtoupper($homeCode), strtoupper($awayCode));
            $indexed[$key] = $row;
        }

        return $indexed;
    }

    public function matchKey(string $homeCode, string $awayCode): string
    {
        return $homeCode.'_'.$awayCode;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function extractGroupCode(array $row): ?string
    {
        $descriptions = $row['GroupName'] ?? [];
        if (!is_array($descriptions)) {
            return null;
        }

        foreach ($descriptions as $description) {
            $text = $description['Description'] ?? null;
            if (!is_string($text)) {
                continue;
            }

            if (preg_match('/Group\s+([A-Z])/i', $text, $matches) === 1) {
                return strtoupper($matches[1]);
            }
        }

        return null;
    }

    /**
     * Maps a FIFA row to one of our stage keys: group, r32, r16, qf, sf, final, third.
     *
     * @param array<string, mixed> $row
     */
    public function extractStageKey(array $row): string
    {
        if ($this->extractGroupCode($row) !== null) {
            return 'group';
        }

        $stageName = '';
        foreach (($row['StageName'] ?? []) as $description) {
            if (is_array($description) && is_string($description['Description'] ?? null)) {
                $stageName .= ' '.$description['Description'];
            }
        }
        $stageName = strtolower($stageName);

        return match (true) {
            str_contains($stageName, 'round of 32') => 'r32',
            str_contains($stageName, 'round of 16') => 'r16',
            str_contains($stageName, 'quarter') => 'qf',
            str_contains($stageName, 'semi') => 'sf',
            str_contains($stageName, 'third') || str_contains($stageName, '3rd') => 'third',
            str_contains($stageName, 'final') => 'final',
            default => 'group',
        };
    }

    /**
     * Team FIFA code (3 letters) for 'Home'/'Away', or null when undecided.
     *
     * @param array<string, mixed> $row
     */
    public function teamCode(array $row, string $side): ?string
    {
        $sideData = $row[$side] ?? null;
        if (!is_array($sideData)) {
            return null;
        }

        $code = $sideData['Abbreviation'] ?? null;

        return is_string($code) && $code !== '' ? strtoupper($code) : null;
    }

    /**
     * True when FIFA has assigned real teams on both sides (no bracket placeholders).
     *
     * @param array<string, mixed> $row
     */
    public function hasBothTeamsConfirmed(array $row): bool
    {
        if ($this->teamPlaceholder($row, 'Home') !== null || $this->teamPlaceholder($row, 'Away') !== null) {
            return false;
        }

        $homeCode = $this->teamCode($row, 'Home');
        $awayCode = $this->teamCode($row, 'Away');

        if ($homeCode === null || $awayCode === null) {
            return false;
        }

        return $this->isRealTeamCode($homeCode) && $this->isRealTeamCode($awayCode);
    }

    public function isRealTeamCode(string $code): bool
    {
        return preg_match('/^[A-Z]{3}$/', strtoupper($code)) === 1;
    }

    /**
     * Bracket placeholder (e.g. "A1", "W73") for an undecided knockout slot.
     *
     * @param array<string, mixed> $row
     */
    public function teamPlaceholder(array $row, string $side): ?string
    {
        $placeholder = $row['Home' === $side ? 'PlaceholderA' : 'PlaceholderB'] ?? null;

        return is_string($placeholder) && $placeholder !== '' ? $placeholder : null;
    }

    /**
     * Kickoff date as an ISO-8601 UTC string, or null.
     *
     * @param array<string, mixed> $row
     */
    public function kickoffIso(array $row): ?string
    {
        $date = $row['Date'] ?? null;

        return is_string($date) && $date !== '' ? $date : null;
    }

    /**
     * FIFA ResultType:
     * 0 = not finished (scheduled or in progress, including live penalty shootout)
     * 1 = full time
     * 2 = decided on penalties
     * 3 = decided after extra time
     * Penalty scores alone do not mean finished — during shootouts ResultType stays 0 until the round ends.
     *
     * @param array<string, mixed> $row
     */
    public function isFinished(array $row): bool
    {
        $resultType = $row['ResultType'] ?? null;

        return $resultType === 1 || $resultType === 2 || $resultType === 3;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function isPenaltyShootoutInProgress(array $row): bool
    {
        if ($this->isFinished($row)) {
            return false;
        }

        return $this->extractPenaltyScores($row) !== null;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{home: int, away: int}|null
     */
    public function extractScores(array $row): ?array
    {
        $homeScore = $row['HomeTeamScore'] ?? null;
        $awayScore = $row['AwayTeamScore'] ?? null;

        if (!is_int($homeScore) && !is_numeric($homeScore)) {
            return null;
        }

        if (!is_int($awayScore) && !is_numeric($awayScore)) {
            return null;
        }

        return [
            'home' => (int) $homeScore,
            'away' => (int) $awayScore,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{home: int, away: int}|null
     */
    public function extractPenaltyScores(array $row): ?array
    {
        $homeScore = $row['HomeTeamPenaltyScore'] ?? null;
        $awayScore = $row['AwayTeamPenaltyScore'] ?? null;

        if (!is_int($homeScore) && !is_numeric($homeScore)) {
            return null;
        }

        if (!is_int($awayScore) && !is_numeric($awayScore)) {
            return null;
        }

        return [
            'home' => (int) $homeScore,
            'away' => (int) $awayScore,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public function extractMatchId(array $row): ?string
    {
        $id = $row['IdMatch'] ?? null;

        return is_string($id) || is_numeric($id) ? (string) $id : null;
    }
}
