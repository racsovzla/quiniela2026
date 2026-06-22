<?php

namespace App\Tests\Service;

use App\Service\FifaCalendarClient;
use App\Service\TournamentStandingsService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

class TournamentStandingsServiceTest extends TestCase
{
    private TournamentStandingsService $service;

    protected function setUp(): void
    {
        $this->service = new TournamentStandingsService(new FifaCalendarClient(new MockHttpClient()));
    }

    public function testMarksMathematicallyQualifiedTeam(): void
    {
        $rows = [
            $this->groupMatch('A', 'AAA', 'BBB', 1, 0, true),
            $this->groupMatch('A', 'AAA', 'CCC', 1, 0, true),
            $this->groupMatch('A', 'AAA', 'DDD', 1, 0, true),
            $this->groupMatch('A', 'BBB', 'CCC', null, null, false),
            $this->groupMatch('A', 'BBB', 'DDD', null, null, false),
            $this->groupMatch('A', 'CCC', 'DDD', null, null, false),
        ];

        $groups = $this->service->standingsByGroup($rows);

        self::assertSame('qualified', $this->statusFor($groups['A'], 'AAA'));
        self::assertNull($this->statusFor($groups['A'], 'BBB'));
    }

    public function testMarksMathematicallyEliminatedTeam(): void
    {
        $rows = [
            $this->groupMatch('B', 'AAA', 'DDD', 1, 0, true),
            $this->groupMatch('B', 'BBB', 'DDD', 1, 0, true),
            $this->groupMatch('B', 'CCC', 'DDD', 1, 0, true),
            $this->groupMatch('B', 'AAA', 'BBB', null, null, false),
            $this->groupMatch('B', 'AAA', 'CCC', null, null, false),
            $this->groupMatch('B', 'BBB', 'CCC', null, null, false),
        ];

        $groups = $this->service->standingsByGroup($rows);

        self::assertSame('eliminated', $this->statusFor($groups['B'], 'DDD'));
        self::assertNull($this->statusFor($groups['B'], 'AAA'));
    }

    public function testMarksFinalPositionsWhenGroupIsComplete(): void
    {
        $rows = [
            $this->groupMatch('C', 'AAA', 'BBB', 2, 0, true),
            $this->groupMatch('C', 'AAA', 'CCC', 1, 0, true),
            $this->groupMatch('C', 'AAA', 'DDD', 1, 1, true),
            $this->groupMatch('C', 'BBB', 'CCC', 0, 1, true),
            $this->groupMatch('C', 'BBB', 'DDD', 3, 0, true),
            $this->groupMatch('C', 'CCC', 'DDD', 2, 2, true),
        ];

        $groups = $this->service->standingsByGroup($rows);

        self::assertSame('qualified', $this->statusFor($groups['C'], 'AAA'));
        self::assertSame('qualified', $this->statusFor($groups['C'], 'CCC'));
        self::assertSame('eliminated', $this->statusFor($groups['C'], 'BBB'));
        self::assertSame('eliminated', $this->statusFor($groups['C'], 'DDD'));
    }

    /**
     * @param list<array{code: string, status: ?string}> $table
     */
    private function statusFor(array $table, string $code): ?string
    {
        foreach ($table as $team) {
            if ($team['code'] === $code) {
                return $team['status'];
            }
        }

        self::fail(sprintf('Team %s not found in table.', $code));
    }

    /**
     * @return array<string, mixed>
     */
    private function groupMatch(
        string $group,
        string $home,
        string $away,
        ?int $homeScore,
        ?int $awayScore,
        bool $finished,
    ): array {
        return [
            'GroupName' => [['Description' => 'Group '.$group]],
            'Home' => ['Abbreviation' => $home],
            'Away' => ['Abbreviation' => $away],
            'HomeTeamScore' => $homeScore,
            'AwayTeamScore' => $awayScore,
            'ResultType' => $finished ? 1 : 0,
        ];
    }
}
