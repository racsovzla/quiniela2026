<?php

namespace App\Tests\Service;

use App\Entity\Fixture;
use App\Entity\Prediction;
use App\Entity\User;
use App\Repository\PredictionRepository;
use App\Service\ScoringService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ScoringServiceTest extends TestCase
{
    private PredictionRepository&MockObject $predictionRepository;

    protected function setUp(): void
    {
        $this->predictionRepository = $this->createMock(PredictionRepository::class);
    }

    public function testOfficialPointsIgnoreScheduledMatchesWithLoadedScore(): void
    {
        $service = new ScoringService($this->predictionRepository);

        $user = (new User())
            ->setName('Alice')
            ->setEmail('alice@example.com')
            ->setPaymentValidatedAt(new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')));

        $fixture = (new Fixture())
            ->setStatus(Fixture::STATUS_SCHEDULED)
            ->setKickoffAt(new \DateTimeImmutable('2026-06-11 19:00:00', new \DateTimeZone('UTC')))
            ->setHomeScore(2)
            ->setAwayScore(1);

        $prediction = (new Prediction())
            ->setUser($user)
            ->setFixture($fixture)
            ->setPredictedHomeScore(2)
            ->setPredictedAwayScore(1);

        self::assertSame(0, $service->calculatePoints($prediction));
        self::assertSame(3, $service->calculateLivePoints($prediction));
    }

    public function testWeeklyLeaderboardAggregatesAndSortsRows(): void
    {
        $service = new ScoringService($this->predictionRepository);
        $nowUtc = new \DateTimeImmutable('2026-06-20 20:00:00', new \DateTimeZone('UTC'));

        $this->predictionRepository
            ->expects(self::once())
            ->method('findFinishedBetweenKickoff')
            ->willReturn([
                $this->buildPrediction('Alice', 1, 2, 1, 2, 1, new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'))),
                $this->buildPrediction('Bob', 2, 2, 1, 1, 0, new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'))),
                $this->buildPrediction('Bob', 2, 2, 2, 2, 2, new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'))),
            ]);

        $rows = $service->weeklyLeaderboard($nowUtc, 7);

        self::assertCount(2, $rows);
        self::assertSame('Bob', $rows[0]['name']);
        self::assertSame(4, $rows[0]['points']);
        self::assertSame('Alice', $rows[1]['name']);
        self::assertSame(3, $rows[1]['points']);
    }

    public function testWeeklyLeaderboardDoesNotUseNameAsTieBreaker(): void
    {
        $service = new ScoringService($this->predictionRepository);
        $nowUtc = new \DateTimeImmutable('2026-06-20 20:00:00', new \DateTimeZone('UTC'));

        $this->predictionRepository
            ->expects(self::once())
            ->method('findFinishedBetweenKickoff')
            ->willReturn([
                $this->buildPrediction('Zoe', 20, 1, 0, 1, 0, new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'))),
                $this->buildPrediction('Ana', 10, 1, 0, 1, 0, new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'))),
            ]);

        $rows = $service->weeklyLeaderboard($nowUtc, 7);

        self::assertCount(2, $rows);
        self::assertSame(10, $rows[0]['userId']);
        self::assertSame('Ana', $rows[0]['name']);
        self::assertSame(20, $rows[1]['userId']);
        self::assertSame('Zoe', $rows[1]['name']);
        self::assertSame($rows[0]['points'], $rows[1]['points']);
        self::assertSame($rows[0]['exactHits'], $rows[1]['exactHits']);
    }

    public function testActiveStreakCountsConsecutiveScoringMatches(): void
    {
        $service = new ScoringService($this->predictionRepository);

        $this->predictionRepository
            ->expects(self::once())
            ->method('findFinishedOrderedByUserAndKickoffDesc')
            ->willReturn([
                $this->buildPrediction('Alice', 10, 1, 0, 1, 0, new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'))),
                $this->buildPrediction('Alice', 10, 2, 2, 2, 2, new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'))),
                $this->buildPrediction('Alice', 10, 0, 1, 1, 0, new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'))),
                $this->buildPrediction('Bob', 20, 1, 1, 1, 1, new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'))),
                $this->buildPrediction('Bob', 20, 0, 2, 1, 0, new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'))),
            ]);

        $streaks = $service->activeStreakByUser();

        self::assertSame(2, $streaks[10]);
        self::assertSame(1, $streaks[20]);
    }

    public function testPointsAreZeroWhenPaymentNotValidated(): void
    {
        $service = new ScoringService($this->predictionRepository);

        $prediction = $this->buildPrediction('Alice', 1, 1, 0, 1, 0, null, new \DateTimeImmutable('2026-06-11 19:00:00', new \DateTimeZone('UTC')));

        self::assertSame(0, $service->calculatePoints($prediction));
    }

    public function testPointsStartFromPaymentValidationTime(): void
    {
        $service = new ScoringService($this->predictionRepository);

        $paymentAt = new \DateTimeImmutable('2026-06-11 19:00:00', new \DateTimeZone('UTC'));

        $beforePaymentKickoff = new \DateTimeImmutable('2026-06-11 18:59:00', new \DateTimeZone('UTC'));
        $afterPaymentKickoff = new \DateTimeImmutable('2026-06-11 19:00:00', new \DateTimeZone('UTC'));

        $predictionBefore = $this->buildPrediction('Alice', 1, 2, 1, 2, 1, $paymentAt, $beforePaymentKickoff);
        $predictionAfter = $this->buildPrediction('Alice', 1, 2, 1, 2, 1, $paymentAt, $afterPaymentKickoff);

        self::assertSame(0, $service->calculatePoints($predictionBefore));
        self::assertSame(3, $service->calculatePoints($predictionAfter));
    }

    public function testExactScoreAwardsThreePointsOnly(): void
    {
        $service = new ScoringService($this->predictionRepository);

        $paymentAt = new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'));
        $prediction = $this->buildPrediction('Alice', 1, 3, 1, 3, 1, $paymentAt);

        self::assertSame(3, $service->calculatePoints($prediction));
    }

    public function testCorrectWinnerAwardsOnePoint(): void
    {
        $service = new ScoringService($this->predictionRepository);

        $paymentAt = new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'));
        $prediction = $this->buildPrediction('Alice', 1, 2, 0, 3, 1, $paymentAt);

        self::assertSame(1, $service->calculatePoints($prediction));
    }

    public function testCorrectDrawAwardsOnePoint(): void
    {
        $service = new ScoringService($this->predictionRepository);

        $paymentAt = new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'));
        $prediction = $this->buildPrediction('Alice', 1, 1, 1, 0, 0, $paymentAt);

        self::assertSame(1, $service->calculatePoints($prediction));
    }

    public function testWrongPredictionAwardsZeroPoints(): void
    {
        $service = new ScoringService($this->predictionRepository);

        $paymentAt = new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'));
        $prediction = $this->buildPrediction('Alice', 1, 0, 2, 2, 0, $paymentAt);

        self::assertSame(0, $service->calculatePoints($prediction));
    }

    public function testLeaderboardTotalMatchesSumOfIndividualPredictions(): void
    {
        $service = new ScoringService($this->predictionRepository);

        $paymentAt = new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'));
        $predictions = [
            $this->buildPrediction('Alice', 1, 2, 1, 2, 1, $paymentAt),
            $this->buildPrediction('Alice', 1, 1, 0, 3, 0, $paymentAt),
            $this->buildPrediction('Bob', 2, 0, 0, 1, 1, $paymentAt),
            $this->buildPrediction('Bob', 2, 2, 2, 2, 2, $paymentAt),
            $this->buildPrediction('Bob', 2, 1, 1, 0, 2, $paymentAt),
        ];

        $this->predictionRepository
            ->expects(self::once())
            ->method('findAll')
            ->willReturn($predictions);

        $rows = $service->leaderboard();

        $expectedByUser = [];
        foreach ($predictions as $prediction) {
            $userId = $prediction->getUser()->getId();
            $expectedByUser[$userId] = ($expectedByUser[$userId] ?? 0) + $service->calculatePoints($prediction);
        }

        foreach ($rows as $row) {
            self::assertSame($expectedByUser[$row['userId']], $row['points']);
        }
    }

    private function buildPrediction(
        string $name,
        int $userId,
        int $predHome,
        int $predAway,
        int $realHome,
        int $realAway,
        ?\DateTimeImmutable $paymentValidatedAt = null,
        ?\DateTimeImmutable $kickoffAt = null,
    ): Prediction {
        $user = (new User())
            ->setName($name)
            ->setEmail(sprintf('%s-%d@example.com', strtolower($name), $userId))
            ->setPaymentValidatedAt($paymentValidatedAt);

        $fixture = (new Fixture())
            ->setStatus(Fixture::STATUS_FINISHED)
            ->setKickoffAt($kickoffAt ?? new \DateTimeImmutable('2026-06-11 19:00:00', new \DateTimeZone('UTC')))
            ->setHomeScore($realHome)
            ->setAwayScore($realAway);

        $prediction = (new Prediction())
            ->setUser($user)
            ->setFixture($fixture)
            ->setPredictedHomeScore($predHome)
            ->setPredictedAwayScore($predAway);

        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $userId);

        return $prediction;
    }
}
