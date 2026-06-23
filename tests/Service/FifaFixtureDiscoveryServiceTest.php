<?php

namespace App\Tests\Service;

use App\Entity\Fixture;
use App\Entity\Team;
use App\Entity\TournamentGroup;
use App\Repository\FixtureRepository;
use App\Repository\TeamRepository;
use App\Repository\TournamentGroupRepository;
use App\Service\FifaCalendarClient;
use App\Service\FifaFixtureDiscoveryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FifaFixtureDiscoveryServiceTest extends TestCase
{
    private FixtureRepository&MockObject $fixtureRepository;
    private TeamRepository&MockObject $teamRepository;
    private TournamentGroupRepository&MockObject $groupRepository;
    private FifaCalendarClient&MockObject $fifaCalendarClient;
    private EntityManagerInterface&MockObject $entityManager;

    protected function setUp(): void
    {
        $this->fixtureRepository = $this->createMock(FixtureRepository::class);
        $this->teamRepository = $this->createMock(TeamRepository::class);
        $this->groupRepository = $this->createMock(TournamentGroupRepository::class);
        $this->fifaCalendarClient = $this->createMock(FifaCalendarClient::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
    }

    public function testCreatesKnockoutFixtureWithPhaseGroup(): void
    {
        $home = (new Team())->setCode('ARG')->setName('Argentina');
        $away = (new Team())->setCode('BRA')->setName('Brazil');
        $phaseGroup = (new TournamentGroup())->setCode('r32')->setName('Dieciseisavos');

        $row = $this->knockoutRow('ARG', 'BRA', '400099999', '2026-06-28T18:00:00Z');

        $this->fifaCalendarClient->method('hasBothTeamsConfirmed')->willReturn(true);
        $this->fifaCalendarClient->method('extractStageKey')->willReturn(Fixture::STAGE_R32);
        $this->fifaCalendarClient->method('teamCode')->willReturnCallback(
            static fn (array $r, string $side): ?string => $side === 'Home' ? 'ARG' : 'BRA',
        );
        $this->fifaCalendarClient->method('kickoffIso')->willReturn('2026-06-28T18:00:00Z');
        $this->fifaCalendarClient->method('extractMatchId')->willReturn('400099999');

        $this->teamRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria) => match ($criteria['code'] ?? null) {
                'ARG' => $home,
                'BRA' => $away,
                default => null,
            },
        );

        $this->fixtureRepository->method('findOneByFifaMatchId')->willReturn(null);
        $this->fixtureRepository->method('findOneByTeamsAndStage')->willReturn(null);

        $this->groupRepository->method('findOneBy')->with(['code' => 'r32'])->willReturn($phaseGroup);

        $this->entityManager->expects(self::once())->method('persist')->with(self::callback(
            static function (Fixture $fixture) use ($phaseGroup): bool {
                return $fixture->getStage() === Fixture::STAGE_R32
                    && $fixture->getGroup() === $phaseGroup
                    && $fixture->getFifaMatchId() === '400099999';
            },
        ));
        $this->entityManager->expects(self::once())->method('flush');

        $service = $this->createService();
        $stats = $service->syncFromRows([$row]);

        self::assertSame(1, $stats['created']);
        self::assertSame(0, $stats['updated']);
        self::assertCount(1, $stats['createdFixtures']);
    }

    public function testSkipsWhenFifaMatchIdAlreadyExists(): void
    {
        $phaseGroup = (new TournamentGroup())->setCode('r32')->setName('Dieciseisavos');
        $existing = (new Fixture())
            ->setHomeTeam((new Team())->setCode('ARG')->setName('Argentina'))
            ->setAwayTeam((new Team())->setCode('BRA')->setName('Brazil'))
            ->setKickoffAt(new \DateTimeImmutable('2026-06-28 18:00:00', new \DateTimeZone('UTC')))
            ->setStage(Fixture::STAGE_R32)
            ->setFifaMatchId('400099999')
            ->setGroup($phaseGroup);

        $row = $this->knockoutRow('ARG', 'BRA', '400099999', '2026-06-28T18:00:00Z');

        $this->fifaCalendarClient->method('hasBothTeamsConfirmed')->willReturn(true);
        $this->fifaCalendarClient->method('extractStageKey')->willReturn(Fixture::STAGE_R32);
        $this->fifaCalendarClient->method('teamCode')->willReturnCallback(
            static fn (array $r, string $side): ?string => $side === 'Home' ? 'ARG' : 'BRA',
        );
        $this->fifaCalendarClient->method('kickoffIso')->willReturn('2026-06-28T18:00:00Z');
        $this->fifaCalendarClient->method('extractMatchId')->willReturn('400099999');

        $this->teamRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria) => (new Team())->setCode($criteria['code'])->setName($criteria['code']),
        );

        $this->groupRepository->method('findOneBy')->with(['code' => 'r32'])->willReturn($phaseGroup);
        $this->fixtureRepository->method('findOneByFifaMatchId')->willReturn($existing);
        $this->entityManager->expects(self::never())->method('persist');

        $service = $this->createService();
        $stats = $service->syncFromRows([$row]);

        self::assertSame(0, $stats['created']);
        self::assertSame(0, $stats['updated']);
        self::assertSame(1, $stats['skipped']);
    }

    public function testSkipsWhenTeamsAreUndefined(): void
    {
        $row = $this->knockoutRow('ARG', 'BRA', '400099999', '2026-06-28T18:00:00Z');

        $this->fifaCalendarClient->method('hasBothTeamsConfirmed')->willReturn(false);
        $this->entityManager->expects(self::never())->method('persist');

        $service = $this->createService();
        $stats = $service->syncFromRows([$row]);

        self::assertSame(0, $stats['created']);
        self::assertSame(1, $stats['skipped']);
    }

    public function testSkipsWhenOnlyHomeTeamIsKnown(): void
    {
        $row = [
            'Home' => ['Abbreviation' => 'GER'],
            'Away' => null,
            'IdMatch' => '400021513',
            'Date' => '2026-06-29T20:30:00Z',
            'StageName' => [['Description' => 'Round of 32']],
        ];

        $client = new FifaCalendarClient($this->createMock(\Symfony\Contracts\HttpClient\HttpClientInterface::class));
        $service = new FifaFixtureDiscoveryService(
            $client,
            $this->entityManager,
            $this->fixtureRepository,
            $this->teamRepository,
            $this->groupRepository,
            $this->createMock(LoggerInterface::class),
        );

        $this->entityManager->expects(self::never())->method('persist');

        $stats = $service->syncFromRows([$row]);

        self::assertSame(0, $stats['created']);
        self::assertSame(1, $stats['skipped']);
    }

    public function testUpdatesKickoffWhenFifaDateChangesToFuture(): void
    {
        $home = (new Team())->setCode('FRA')->setName('France');
        $away = (new Team())->setCode('IRQ')->setName('Iraq');
        $group = (new TournamentGroup())->setCode('D')->setName('Group D');

        $existing = (new Fixture())
            ->setHomeTeam($home)
            ->setAwayTeam($away)
            ->setGroup($group)
            ->setStage(Fixture::STAGE_GROUP)
            ->setKickoffAt(new \DateTimeImmutable('2026-06-22 18:00:00', new \DateTimeZone('UTC')))
            ->setStatus(Fixture::STATUS_SCHEDULED)
            ->setFifaMatchId('400021500')
            ->setPredictionsEmailSentAt(new \DateTimeImmutable('2026-06-22 17:00:00', new \DateTimeZone('UTC')));

        $row = [
            'Home' => ['Abbreviation' => 'FRA'],
            'Away' => ['Abbreviation' => 'IRQ'],
            'IdMatch' => '400021500',
            'Date' => '2026-06-25T20:00:00Z',
            'GroupName' => [['Description' => 'Group D']],
        ];

        $this->fifaCalendarClient->method('hasBothTeamsConfirmed')->willReturn(true);
        $this->fifaCalendarClient->method('extractStageKey')->willReturn(Fixture::STAGE_GROUP);
        $this->fifaCalendarClient->method('extractGroupCode')->willReturn('D');
        $this->fifaCalendarClient->method('teamCode')->willReturnCallback(
            static fn (array $r, string $side): ?string => $side === 'Home' ? 'FRA' : 'IRQ',
        );
        $this->fifaCalendarClient->method('kickoffIso')->willReturn('2026-06-25T20:00:00Z');
        $this->fifaCalendarClient->method('extractMatchId')->willReturn('400021500');

        $this->teamRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria) => match ($criteria['code'] ?? null) {
                'FRA' => $home,
                'IRQ' => $away,
                default => null,
            },
        );

        $this->groupRepository->method('findOneBy')->with(['code' => 'D'])->willReturn($group);
        $this->fixtureRepository->method('findOneByFifaMatchId')->willReturn($existing);
        $this->entityManager->expects(self::once())->method('persist')->with($existing);
        $this->entityManager->expects(self::once())->method('flush');

        $service = $this->createService();
        $stats = $service->syncFromRows([$row]);

        self::assertSame(0, $stats['created']);
        self::assertSame(1, $stats['updated']);
        self::assertSame(Fixture::STATUS_SCHEDULED, $existing->getStatus());
        self::assertNull($existing->getPredictionsEmailSentAt());
    }

    /**
     * @return array<string, mixed>
     */
    private function knockoutRow(string $homeCode, string $awayCode, string $matchId, string $kickoff): array
    {
        return [
            'Home' => ['Abbreviation' => $homeCode],
            'Away' => ['Abbreviation' => $awayCode],
            'IdMatch' => $matchId,
            'Date' => $kickoff,
            'StageName' => [['Description' => 'Round of 32']],
        ];
    }

    private function createService(): FifaFixtureDiscoveryService
    {
        return new FifaFixtureDiscoveryService(
            $this->fifaCalendarClient,
            $this->entityManager,
            $this->fixtureRepository,
            $this->teamRepository,
            $this->groupRepository,
            $this->createMock(LoggerInterface::class),
        );
    }
}
