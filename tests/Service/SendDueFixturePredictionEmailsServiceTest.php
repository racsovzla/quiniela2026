<?php

namespace App\Tests\Service;

use App\Entity\Fixture;
use App\Entity\Team;
use App\Repository\FixtureRepository;
use App\Repository\PredictionRepository;
use App\Repository\UserRepository;
use App\Service\FixturePredictionEmailService;
use App\Service\SendDueFixturePredictionEmailsService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class SendDueFixturePredictionEmailsServiceTest extends TestCase
{
    public function testDispatchDueEmailsSkipsWhenNoPredictions(): void
    {
        $fixture = $this->createFixture(new \DateTimeImmutable('2026-06-11 19:00:00', new \DateTimeZone('UTC')));

        $fixtureRepository = $this->createMock(FixtureRepository::class);
        $fixtureRepository
            ->expects(self::once())
            ->method('findDueForPredictionEmail')
            ->willReturn([$fixture]);

        $predictionRepository = $this->createMock(PredictionRepository::class);
        $predictionRepository
            ->expects(self::once())
            ->method('findByFixtureWithUser')
            ->with($fixture)
            ->willReturn([]);

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects(self::never())->method('findApprovedVerifiedRecipients');

        $emailService = $this->createMock(FixturePredictionEmailService::class);
        $emailService->expects(self::never())->method('sendFixturePredictionsSummary');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($fixture);
        $entityManager->expects(self::once())->method('flush');

        $service = new SendDueFixturePredictionEmailsService(
            $fixtureRepository,
            $predictionRepository,
            $userRepository,
            $emailService,
            $entityManager,
        );

        $nowUtc = new \DateTimeImmutable('2026-06-11 18:55:00', new \DateTimeZone('UTC'));
        $stats = $service->dispatchDueEmails($nowUtc);

        self::assertSame(1, $stats['processed']);
        self::assertSame(0, $stats['sent']);
        self::assertSame(1, $stats['skipped']);
        self::assertNotNull($fixture->getPredictionsEmailSentAt());
    }

    public function testDispatchDueEmailsDryRunDoesNotPersist(): void
    {
        $fixture = $this->createFixture(new \DateTimeImmutable('2026-06-11 19:00:00', new \DateTimeZone('UTC')));

        $fixtureRepository = $this->createMock(FixtureRepository::class);
        $fixtureRepository->method('findDueForPredictionEmail')->willReturn([$fixture]);

        $predictionRepository = $this->createMock(PredictionRepository::class);
        $predictionRepository->method('findByFixtureWithUser')->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $service = new SendDueFixturePredictionEmailsService(
            $fixtureRepository,
            $predictionRepository,
            $this->createMock(UserRepository::class),
            $this->createMock(FixturePredictionEmailService::class),
            $entityManager,
        );

        $stats = $service->dispatchDueEmails(dryRun: true);

        self::assertSame(1, $stats['processed']);
        self::assertNull($fixture->getPredictionsEmailSentAt());
    }

    private function createFixture(\DateTimeImmutable $kickoffAt): Fixture
    {
        $homeTeam = (new Team())->setName('Home')->setCode('HOM');
        $awayTeam = (new Team())->setName('Away')->setCode('AWY');

        return (new Fixture())
            ->setHomeTeam($homeTeam)
            ->setAwayTeam($awayTeam)
            ->setKickoffAt($kickoffAt);
    }
}
