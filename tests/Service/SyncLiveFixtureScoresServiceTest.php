<?php

namespace App\Tests\Service;

use App\Entity\Fixture;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Prediction;
use App\Repository\FixtureRepository;
use App\Repository\UserRepository;
use App\Repository\PredictionRepository;
use App\Entity\TournamentGroup;
use App\Service\FifaCalendarClient;
use App\Service\CountryNameResolver;
use App\Service\FifaFixtureDiscoveryService;
use App\Service\WhatsAppMessageFormatter;
use App\Service\WhatsAppService;
use App\Service\SyncLiveFixtureScoresService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class SyncLiveFixtureScoresServiceTest extends TestCase
{
    public function testSyncLiveScoresUpdatesInProgressFixture(): void
    {
        $fixture = $this->createFixture('CIV', 'ECU', new \DateTimeImmutable('2026-06-14 23:00:00', new \DateTimeZone('UTC')));

        $fixtureRepository = $this->createMock(FixtureRepository::class);
        $this->mockFixtureQueries($fixtureRepository, [$fixture]);

        $fifaClient = $this->createMock(FifaCalendarClient::class);
        $fifaClient->method('fetchAllMatches')->willReturn([
            $this->fifaRow('CIV', 'ECU', 1, 0, 0),
        ]);
        $fifaClient->method('indexByTeamCodes')->willReturnCallback(
            static fn (array $rows): array => ['CIV_ECU' => $rows[0]]
        );
        $fifaClient->method('matchKey')->willReturn('CIV_ECU');
        $fifaClient->method('extractScores')->willReturn(['home' => 1, 'away' => 0]);
        $fifaClient->method('isFinished')->willReturn(false);
        $fifaClient->method('isPostponed')->willReturn(false);
        $fifaClient->method('isSuspendedInPlay')->willReturn(false);
        $fifaClient->method('hasFutureKickoff')->willReturn(false);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($fixture);
        $entityManager->expects(self::once())->method('flush');

        $service = $this->createService($fixtureRepository, $fifaClient, $entityManager);

        $stats = $service->syncLiveScores(
            new \DateTimeImmutable('2026-06-14 23:45:00', new \DateTimeZone('UTC')),
        );

        self::assertSame(1, $stats['checked']);
        self::assertSame(1, $stats['matched']);
        self::assertSame(1, $stats['updated']);
        self::assertSame(0, $stats['finished']);
        self::assertSame(0, $stats['skipped']);
        self::assertSame(1, $fixture->getHomeScore());
        self::assertSame(0, $fixture->getAwayScore());
        self::assertSame(Fixture::STATUS_SCHEDULED, $fixture->getStatus());
    }

    public function testSyncLiveScoresFinalizesFinishedFixture(): void
    {
        $fixture = $this->createFixture('GER', 'CUW', new \DateTimeImmutable('2026-06-14 17:00:00', new \DateTimeZone('UTC')))
            ->setHomeScore(3)
            ->setAwayScore(1);

        $fixtureRepository = $this->createMock(FixtureRepository::class);
        $this->mockFixtureQueries($fixtureRepository, [$fixture]);

        $fifaClient = $this->createMock(FifaCalendarClient::class);
        $fifaClient->method('fetchAllMatches')->willReturn([
            $this->fifaRow('GER', 'CUW', 7, 1, 1),
        ]);
        $fifaClient->method('indexByTeamCodes')->willReturnCallback(
            static fn (array $rows): array => ['GER_CUW' => $rows[0]]
        );
        $fifaClient->method('matchKey')->willReturn('GER_CUW');
        $fifaClient->method('extractScores')->willReturn(['home' => 7, 'away' => 1]);
        $fifaClient->method('isFinished')->willReturn(true);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($fixture);
        $entityManager->expects(self::once())->method('flush');

        $service = $this->createService($fixtureRepository, $fifaClient, $entityManager);

        $stats = $service->syncLiveScores(
            new \DateTimeImmutable('2026-06-14 18:50:00', new \DateTimeZone('UTC')),
        );

        self::assertSame(1, $stats['updated']);
        self::assertSame(1, $stats['finished']);
        self::assertSame(7, $fixture->getHomeScore());
        self::assertSame(1, $fixture->getAwayScore());
        self::assertSame(Fixture::STATUS_FINISHED, $fixture->getStatus());
    }

    public function testSyncLiveScoresSkipsWhenNoChanges(): void
    {
        $fixture = $this->createFixture('GER', 'CUW', new \DateTimeImmutable('2026-06-14 17:00:00', new \DateTimeZone('UTC')))
            ->setHomeScore(7)
            ->setAwayScore(1)
            ->setStatus(Fixture::STATUS_FINISHED);

        $fixtureRepository = $this->createMock(FixtureRepository::class);
        $this->mockFixtureQueries($fixtureRepository, [$fixture]);

        $fifaClient = $this->createMock(FifaCalendarClient::class);
        $fifaClient->method('fetchAllMatches')->willReturn([
            $this->fifaRow('GER', 'CUW', 7, 1, 1),
        ]);
        $fifaClient->method('indexByTeamCodes')->willReturnCallback(
            static fn (array $rows): array => ['GER_CUW' => $rows[0]]
        );
        $fifaClient->method('matchKey')->willReturn('GER_CUW');
        $fifaClient->method('extractScores')->willReturn(['home' => 7, 'away' => 1]);
        $fifaClient->method('isFinished')->willReturn(true);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $service = $this->createService($fixtureRepository, $fifaClient, $entityManager);

        $stats = $service->syncLiveScores(
            new \DateTimeImmutable('2026-06-14 18:50:00', new \DateTimeZone('UTC')),
        );

        self::assertSame(1, $stats['matched']);
        self::assertSame(0, $stats['updated']);
    }

    public function testSyncLiveScoresSkipsWhenNoFifaMatch(): void
    {
        $fixture = $this->createFixture('AAA', 'BBB', new \DateTimeImmutable('2026-06-14 17:00:00', new \DateTimeZone('UTC')));

        $fixtureRepository = $this->createMock(FixtureRepository::class);
        $this->mockFixtureQueries($fixtureRepository, [], [$fixture]);

        $fifaClient = $this->createMock(FifaCalendarClient::class);
        $fifaClient->method('fetchAllMatches')->willReturn([]);
        $fifaClient->method('indexByTeamCodes')->willReturn([]);
        $fifaClient->method('matchKey')->willReturn('AAA_BBB');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $service = $this->createService($fixtureRepository, $fifaClient, $entityManager);

        $stats = $service->syncLiveScores(
            new \DateTimeImmutable('2026-06-14 18:50:00', new \DateTimeZone('UTC')),
        );

        self::assertSame(0, $stats['checked']);
        self::assertSame(0, $stats['matched']);
        self::assertSame(1, $stats['skipped']);
    }

    public function testSyncLiveScoresCatchesUpOldFinishedFixture(): void
    {
        $fixture = $this->createFixture('AUS', 'TUR', new \DateTimeImmutable('2026-06-14 04:00:00', new \DateTimeZone('UTC')));

        $fixtureRepository = $this->createMock(FixtureRepository::class);
        $this->mockFixtureQueries($fixtureRepository, [$fixture]);

        $fifaClient = $this->createMock(FifaCalendarClient::class);
        $fifaClient->method('fetchAllMatches')->willReturn([
            $this->fifaRow('AUS', 'TUR', 2, 0, 1),
        ]);
        $fifaClient->method('indexByTeamCodes')->willReturnCallback(
            static fn (array $rows): array => ['AUS_TUR' => $rows[0]]
        );
        $fifaClient->method('matchKey')->willReturn('AUS_TUR');
        $fifaClient->method('extractScores')->willReturn(['home' => 2, 'away' => 0]);
        $fifaClient->method('isFinished')->willReturn(true);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($fixture);
        $entityManager->expects(self::once())->method('flush');

        $service = $this->createService($fixtureRepository, $fifaClient, $entityManager);

        $stats = $service->syncLiveScores(
            new \DateTimeImmutable('2026-06-15 12:00:00', new \DateTimeZone('UTC')),
        );

        self::assertSame(1, $stats['updated']);
        self::assertSame(1, $stats['finished']);
        self::assertSame(Fixture::STATUS_FINISHED, $fixture->getStatus());
        self::assertSame(2, $fixture->getHomeScore());
        self::assertSame(0, $fixture->getAwayScore());
    }

    public function testSyncLiveScoresDryRunDoesNotPersist(): void
    {
        $fixture = $this->createFixture('CIV', 'ECU', new \DateTimeImmutable('2026-06-14 23:00:00', new \DateTimeZone('UTC')));

        $fixtureRepository = $this->createMock(FixtureRepository::class);
        $this->mockFixtureQueries($fixtureRepository, [], [$fixture]);

        $fifaClient = $this->createMock(FifaCalendarClient::class);
        $fifaClient->method('fetchAllMatches')->willReturn([
            $this->fifaRow('CIV', 'ECU', 0, 0, 0),
        ]);
        $fifaClient->method('indexByTeamCodes')->willReturnCallback(
            static fn (array $rows): array => ['CIV_ECU' => $rows[0]]
        );
        $fifaClient->method('matchKey')->willReturn('CIV_ECU');
        $fifaClient->method('extractScores')->willReturn(['home' => 0, 'away' => 0]);
        $fifaClient->method('isFinished')->willReturn(false);
        $fifaClient->method('isPostponed')->willReturn(false);
        $fifaClient->method('isSuspendedInPlay')->willReturn(false);
        $fifaClient->method('hasFutureKickoff')->willReturn(false);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $service = $this->createService($fixtureRepository, $fifaClient, $entityManager);

        $stats = $service->syncLiveScores(
            new \DateTimeImmutable('2026-06-14 23:45:00', new \DateTimeZone('UTC')),
            dryRun: true,
        );

        self::assertSame(1, $stats['updated']);
        self::assertNull($fixture->getHomeScore());
        self::assertNull($fixture->getAwayScore());
    }

    public function testSyncLiveScoresMarksOverdueFixtureAsPostponed(): void
    {
        $fixture = $this->createFixture('FRA', 'IRQ', new \DateTimeImmutable('2026-06-22 18:00:00', new \DateTimeZone('UTC')));

        $fixtureRepository = $this->createMock(FixtureRepository::class);
        $this->mockFixtureQueries($fixtureRepository, [$fixture], []);

        $fifaClient = $this->createMock(FifaCalendarClient::class);
        $fifaClient->method('fetchAllMatches')->willReturn([
            $this->fifaRow('FRA', 'IRQ', 0, 0, 0),
        ]);
        $fifaClient->method('indexByTeamCodes')->willReturnCallback(
            static fn (array $rows): array => ['FRA_IRQ' => $rows[0]]
        );
        $fifaClient->method('matchKey')->willReturn('FRA_IRQ');
        $fifaClient->method('isFinished')->willReturn(false);
        $fifaClient->method('isPostponed')->willReturn(true);
        $fifaClient->method('isSuspendedInPlay')->willReturn(false);
        $fifaClient->method('hasFutureKickoff')->willReturn(false);
        $fifaClient->method('extractScores')->willReturn(['home' => 0, 'away' => 0]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($fixture);
        $entityManager->expects(self::once())->method('flush');

        $service = $this->createService($fixtureRepository, $fifaClient, $entityManager);

        $stats = $service->syncLiveScores(
            new \DateTimeImmutable('2026-06-22 20:00:00', new \DateTimeZone('UTC')),
        );

        self::assertSame(1, $stats['postponed']);
        self::assertSame(Fixture::STATUS_POSTPONED, $fixture->getStatus());
    }

    public function testSyncLiveScoresAlwaysRunsFixtureDiscovery(): void
    {
        $fixtureRepository = $this->createMock(FixtureRepository::class);
        $this->mockFixtureQueries($fixtureRepository, [], []);

        $fifaClient = $this->createMock(FifaCalendarClient::class);
        $fifaClient->method('fetchAllMatches')->willReturn([]);
        $fifaClient->method('indexByTeamCodes')->willReturn([]);

        $discovery = $this->createMock(FifaFixtureDiscoveryService::class);
        $discovery->expects(self::once())->method('importNewFixtures')->willReturn([
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'createdFixtures' => [],
        ]);

        $service = $this->createService(
            $fixtureRepository,
            $fifaClient,
            $this->createMock(EntityManagerInterface::class),
            fifaFixtureDiscoveryService: $discovery,
        );

        $service->syncLiveScores();
    }

    private function mockFixtureQueries(FixtureRepository $fixtureRepository, array $overdueFixtures, ?array $liveFixtures = null): void
    {
        $liveFixtures ??= $overdueFixtures;

        $fixtureRepository->method('findOverdueForDelayCheck')->willReturn($overdueFixtures);
        $fixtureRepository->method('findScheduledPotentiallyLive')->willReturn($liveFixtures);
    }

    private function createService(
        FixtureRepository $fixtureRepository,
        FifaCalendarClient $fifaClient,
        EntityManagerInterface $entityManager,
        ?UserRepository $userRepository = null,
        ?PredictionRepository $predictionRepository = null,
        ?CountryNameResolver $countryNameResolver = null,
        ?WhatsAppService $whatsAppService = null,
        ?FifaFixtureDiscoveryService $fifaFixtureDiscoveryService = null,
    ): SyncLiveFixtureScoresService {
        return new SyncLiveFixtureScoresService(
            $fixtureRepository,
            $fifaClient,
            $entityManager,
            $userRepository ?? $this->createMock(UserRepository::class),
            $predictionRepository ?? $this->createMock(PredictionRepository::class),
            $countryNameResolver ?? $this->createMock(CountryNameResolver::class),
            $whatsAppService ?? $this->createMock(WhatsAppService::class),
            new WhatsAppMessageFormatter(),
            $fifaFixtureDiscoveryService ?? $this->createEmptyDiscoveryService(),
        );
    }

    private function createEmptyDiscoveryService(): FifaFixtureDiscoveryService
    {
        $discovery = $this->createMock(FifaFixtureDiscoveryService::class);
        $discovery->method('importNewFixtures')->willReturn([
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'createdFixtures' => [],
        ]);

        return $discovery;
    }

    private function createFixture(string $homeCode, string $awayCode, \DateTimeImmutable $kickoffAt): Fixture
    {
        $homeTeam = (new Team())->setName($homeCode)->setCode($homeCode);
        $awayTeam = (new Team())->setName($awayCode)->setCode($awayCode);

        return (new Fixture())
            ->setHomeTeam($homeTeam)
            ->setAwayTeam($awayTeam)
            ->setKickoffAt($kickoffAt);
    }

    /**
     * @return array<string, mixed>
     */
    private function fifaRow(string $homeCode, string $awayCode, int $homeScore, int $awayScore, int $resultType): array
    {
        return [
            'Home' => ['Abbreviation' => $homeCode],
            'Away' => ['Abbreviation' => $awayCode],
            'HomeTeamScore' => $homeScore,
            'AwayTeamScore' => $awayScore,
            'ResultType' => $resultType,
        ];
    }

    public function testSyncLiveScoresSendsWhatsAppWhenPredictionsMissing(): void
    {
        // 1. Fixture that is finishing
        $fixture = $this->createFixture('CIV', 'ECU', new \DateTimeImmutable('2026-06-14 23:00:00', new \DateTimeZone('UTC')));

        // 2. Next scheduled fixture (the one to check predictions for)
        $nextFixture = $this->createFixture('ARG', 'BRA', new \DateTimeImmutable('2026-06-15 18:00:00', new \DateTimeZone('UTC')));

        $fixtureRepository = $this->createMock(FixtureRepository::class);
        $this->mockFixtureQueries($fixtureRepository, [$fixture]);
        // findNextScheduledFixture returns our next fixture
        $fixtureRepository->method('findNextScheduledFixture')->willReturn($nextFixture);

        $fifaClient = $this->createMock(FifaCalendarClient::class);
        $fifaClient->method('fetchAllMatches')->willReturn([
            $this->fifaRow('CIV', 'ECU', 1, 0, 1), // Finished
        ]);
        $fifaClient->method('indexByTeamCodes')->willReturn(['CIV_ECU' => $this->fifaRow('CIV', 'ECU', 1, 0, 1)]);
        $fifaClient->method('matchKey')->willReturn('CIV_ECU');
        $fifaClient->method('extractScores')->willReturn(['home' => 1, 'away' => 0]);
        $fifaClient->method('isFinished')->willReturn(true);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist');
        $entityManager->method('flush');

        // Users
        $user1 = (new User())->setName('Oscar')->setEmail('oscar@test.com');
        $user2 = (new User())->setName('Pedro')->setEmail('pedro@test.com');
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findApprovedVerifiedRecipients')->willReturn([$user1, $user2]);

        // Prediction repo: Oscar has prediction, Pedro does not
        $prediction = new Prediction();
        $predictionRepository = $this->createMock(PredictionRepository::class);
        $predictionRepository->method('findOneByUserAndFixture')->willReturnCallback(
            static function (User $u, Fixture $f) use ($user1, $prediction) {
                if ($u->getEmail() === $user1->getEmail()) {
                    return $prediction;
                }
                return null; // Pedro has no prediction
            }
        );

        $countryNameResolver = $this->createMock(CountryNameResolver::class);
        $countryNameResolver->method('resolveSpanishName')->willReturnCallback(
            static fn ($code, $name) => $name
        );

        // WhatsAppService: should receive sendMessage with Pedro's name
        $whatsAppService = $this->createMock(WhatsAppService::class);
        $whatsAppService
            ->expects(self::once())
            ->method('sendMessage')
            ->with(self::callback(function (string $message) {
                return str_contains($message, 'Pedro')
                    && !str_contains($message, 'Oscar')
                    && str_contains($message, '*ARG* vs *BRA*');
            }));

        $service = $this->createService(
            $fixtureRepository,
            $fifaClient,
            $entityManager,
            $userRepository,
            $predictionRepository,
            $countryNameResolver,
            $whatsAppService
        );

        $service->syncLiveScores(new \DateTimeImmutable('2026-06-14 23:55:00', new \DateTimeZone('UTC')));
    }

    public function testSyncLiveScoresSendsWhatsAppWhenNewFixtureDiscovered(): void
    {
        $fixture = $this->createFixture('CIV', 'ECU', new \DateTimeImmutable('2026-06-14 23:00:00', new \DateTimeZone('UTC')));

        $newFixture = $this->createFixture('ARG', 'BRA', new \DateTimeImmutable('2026-06-28 18:00:00', new \DateTimeZone('UTC')))
            ->setStage(Fixture::STAGE_R32)
            ->setGroup((new TournamentGroup())->setCode('r32')->setName('Dieciseisavos'));

        $fixtureRepository = $this->createMock(FixtureRepository::class);
        $this->mockFixtureQueries($fixtureRepository, [$fixture]);
        $fixtureRepository->method('findNextScheduledFixture')->willReturn(null);

        $fifaClient = $this->createMock(FifaCalendarClient::class);
        $fifaClient->method('fetchAllMatches')->willReturn([
            $this->fifaRow('CIV', 'ECU', 1, 0, 1),
        ]);
        $fifaClient->method('indexByTeamCodes')->willReturn(['CIV_ECU' => $this->fifaRow('CIV', 'ECU', 1, 0, 1)]);
        $fifaClient->method('matchKey')->willReturn('CIV_ECU');
        $fifaClient->method('extractScores')->willReturn(['home' => 1, 'away' => 0]);
        $fifaClient->method('isFinished')->willReturn(true);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist');
        $entityManager->method('flush');

        $discovery = $this->createMock(FifaFixtureDiscoveryService::class);
        $discovery->method('importNewFixtures')->willReturn([
            'created' => 1,
            'updated' => 0,
            'skipped' => 0,
            'createdFixtures' => [$newFixture],
        ]);

        $countryNameResolver = $this->createMock(CountryNameResolver::class);
        $countryNameResolver->method('resolveSpanishName')->willReturnCallback(
            static fn ($code, $name) => $code,
        );

        $whatsAppService = $this->createMock(WhatsAppService::class);
        $whatsAppService
            ->expects(self::once())
            ->method('sendMessage')
            ->with(self::callback(static function (string $message): bool {
                return str_contains($message, 'Nuevo partido en quiniela')
                    && str_contains($message, 'Dieciseisavos')
                    && str_contains($message, '*ARG* vs *BRA*');
            }));

        $service = $this->createService(
            $fixtureRepository,
            $fifaClient,
            $entityManager,
            whatsAppService: $whatsAppService,
            fifaFixtureDiscoveryService: $discovery,
            countryNameResolver: $countryNameResolver,
        );

        $service->syncLiveScores(new \DateTimeImmutable('2026-06-14 23:55:00', new \DateTimeZone('UTC')));
    }

    public function testSyncLiveScoresDoesNotSendNewFixtureWhatsAppWhenDiscoveryEmpty(): void
    {
        $fixture = $this->createFixture('GER', 'CUW', new \DateTimeImmutable('2026-06-14 17:00:00', new \DateTimeZone('UTC')));

        $fixtureRepository = $this->createMock(FixtureRepository::class);
        $this->mockFixtureQueries($fixtureRepository, [$fixture]);
        $fixtureRepository->method('findNextScheduledFixture')->willReturn(null);

        $fifaClient = $this->createMock(FifaCalendarClient::class);
        $fifaClient->method('fetchAllMatches')->willReturn([
            $this->fifaRow('GER', 'CUW', 7, 1, 1),
        ]);
        $fifaClient->method('indexByTeamCodes')->willReturn(['GER_CUW' => $this->fifaRow('GER', 'CUW', 7, 1, 1)]);
        $fifaClient->method('matchKey')->willReturn('GER_CUW');
        $fifaClient->method('extractScores')->willReturn(['home' => 7, 'away' => 1]);
        $fifaClient->method('isFinished')->willReturn(true);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist');
        $entityManager->method('flush');

        $discovery = $this->createMock(FifaFixtureDiscoveryService::class);
        $discovery->expects(self::once())->method('importNewFixtures')->willReturn([
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'createdFixtures' => [],
        ]);

        $whatsAppService = $this->createMock(WhatsAppService::class);
        $whatsAppService->expects(self::never())->method('sendMessage');

        $service = $this->createService(
            $fixtureRepository,
            $fifaClient,
            $entityManager,
            whatsAppService: $whatsAppService,
            fifaFixtureDiscoveryService: $discovery,
        );

        $service->syncLiveScores(new \DateTimeImmutable('2026-06-14 18:50:00', new \DateTimeZone('UTC')));
    }
}
