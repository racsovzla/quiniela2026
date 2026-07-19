<?php

namespace App\Tests\Service;

use App\Service\LeaderboardLockService;
use PHPUnit\Framework\TestCase;

class LeaderboardLockServiceTest extends TestCase
{
    private LeaderboardLockService $service;

    protected function setUp(): void
    {
        $this->service = new LeaderboardLockService();
    }

    public function testLocksPlacesWhenNoMatchesRemain(): void
    {
        $rows = $this->service->annotate([
            ['userId' => 1, 'name' => 'A', 'points' => 30, 'exactHits' => 5, 'rank' => 1],
            ['userId' => 2, 'name' => 'B', 'points' => 20, 'exactHits' => 3, 'rank' => 2],
            ['userId' => 3, 'name' => 'C', 'points' => 10, 'exactHits' => 1, 'rank' => 3],
            ['userId' => 4, 'name' => 'D', 'points' => 5, 'exactHits' => 0, 'rank' => 4],
        ], 0, 0);

        self::assertSame(1, $rows[0]['lockedPlace']);
        self::assertSame(2, $rows[1]['lockedPlace']);
        self::assertSame(3, $rows[2]['lockedPlace']);
        self::assertNull($rows[3]['lockedPlace']);
    }

    public function testDoesNotLockWhenChallengerCanStillCatch(): void
    {
        $rows = $this->service->annotate([
            ['userId' => 1, 'name' => 'A', 'points' => 10, 'exactHits' => 2, 'rank' => 1],
            ['userId' => 2, 'name' => 'B', 'points' => 8, 'exactHits' => 1, 'rank' => 2],
        ], 6, 1);

        self::assertNull($rows[0]['lockedPlace']);
        self::assertNull($rows[1]['lockedPlace']);
    }

    public function testLocksFirstWhenGapExceedsRemainingBudget(): void
    {
        $rows = $this->service->annotate([
            ['userId' => 1, 'name' => 'A', 'points' => 20, 'exactHits' => 4, 'rank' => 1],
            ['userId' => 2, 'name' => 'B', 'points' => 10, 'exactHits' => 2, 'rank' => 2],
        ], 6, 2);

        self::assertSame(1, $rows[0]['lockedPlace']);
        self::assertSame(2, $rows[1]['lockedPlace']);
    }

    public function testSharedFirstBothLockWhenNobodyElseCanCatch(): void
    {
        $rows = $this->service->annotate([
            ['userId' => 1, 'name' => 'A', 'points' => 15, 'exactHits' => 3, 'rank' => 1],
            ['userId' => 2, 'name' => 'B', 'points' => 15, 'exactHits' => 3, 'rank' => 1],
            ['userId' => 3, 'name' => 'C', 'points' => 5, 'exactHits' => 0, 'rank' => 3],
        ], 0, 0);

        self::assertSame(1, $rows[0]['lockedPlace']);
        self::assertSame(1, $rows[1]['lockedPlace']);
        self::assertSame(3, $rows[2]['lockedPlace']);
    }

    public function testLockedWinnersGroupsByPlace(): void
    {
        $rows = [
            ['userId' => 1, 'name' => 'A', 'points' => 30, 'exactHits' => 5, 'rank' => 1, 'lockedPlace' => 1],
            ['userId' => 2, 'name' => 'B', 'points' => 20, 'exactHits' => 3, 'rank' => 2, 'lockedPlace' => 2],
            ['userId' => 3, 'name' => 'C', 'points' => 10, 'exactHits' => 1, 'rank' => 3, 'lockedPlace' => null],
        ];

        $winners = $this->service->lockedWinners($rows);

        self::assertCount(1, $winners[1]);
        self::assertCount(1, $winners[2]);
        self::assertArrayNotHasKey(3, $winners);
    }
}
