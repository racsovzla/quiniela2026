<?php

namespace App\Tests\Service;

use App\Repository\FixtureRepository;
use App\Service\FifaCalendarClient;
use App\Service\TournamentMatchBudgetService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\Cache\CacheInterface;

class TournamentMatchBudgetServiceTest extends TestCase
{
    public function testCountsUnfinishedMatchesAndPointBudgetFromFifaRows(): void
    {
        $service = new TournamentMatchBudgetService(
            new FifaCalendarClient(new MockHttpClient()),
            $this->createStub(FixtureRepository::class),
            $this->createStub(CacheInterface::class),
        );

        $budget = $service->fromFifaRows([
            $this->match('group', true),
            $this->match('group', false),
            $this->match('r16', false),
            $this->match('final', true),
        ]);

        self::assertSame(4, $budget['total']);
        self::assertSame(2, $budget['remaining']);
        self::assertSame(9, $budget['maxPoints']);
        self::assertSame(2, $budget['maxExactHits']);
        self::assertTrue($budget['fromFifa']);
    }

    public function testFallsBackToDatabaseWhenFifaFails(): void
    {
        $fifa = $this->createMock(FifaCalendarClient::class);
        $fifa->expects(self::once())->method('fetchAllMatches')->willThrowException(new \RuntimeException('down'));

        $fixtures = $this->createMock(FixtureRepository::class);
        $fixtures->expects(self::exactly(2))->method('count')->willReturnCallback(static function (array $criteria): int {
            return ($criteria['status'] ?? '') === 'scheduled' ? 5 : 10;
        });

        $service = new TournamentMatchBudgetService($fifa, $fixtures, new ArrayAdapter());
        $budget = $service->current();

        self::assertSame(15, $budget['total']);
        self::assertSame(5, $budget['remaining']);
        self::assertSame(30, $budget['maxPoints']);
        self::assertFalse($budget['fromFifa']);
    }

    /**
     * @return array<string, mixed>
     */
    private function match(string $stage, bool $finished): array
    {
        $stageName = match ($stage) {
            'group' => 'Group Stage',
            'r16' => 'Round of 16',
            'final' => 'Final',
            default => $stage,
        };

        return [
            'GroupName' => $stage === 'group' ? [['Description' => 'Group A']] : [],
            'StageName' => [['Description' => $stageName]],
            'ResultType' => $finished ? 1 : 0,
            'Home' => ['Abbreviation' => 'AAA'],
            'Away' => ['Abbreviation' => 'BBB'],
        ];
    }
}
