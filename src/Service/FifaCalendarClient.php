<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class FifaCalendarClient
{
    public const GROUP_STAGE_API_URL = 'https://api.fifa.com/api/v3/calendar/matches?language=en&idCompetition=17&idSeason=285023&idStage=289273&count=400';

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
        try {
            $response = $this->httpClient->request('GET', self::GROUP_STAGE_API_URL);
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
     * @param array<string, mixed> $row
     */
    public function isFinished(array $row): bool
    {
        return ($row['ResultType'] ?? null) === 1;
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
}
