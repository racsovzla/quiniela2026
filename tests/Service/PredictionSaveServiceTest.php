<?php

namespace App\Tests\Service;

use App\Entity\Fixture;
use App\Entity\Prediction;
use App\Entity\Team;
use App\Entity\User;
use App\Repository\PredictionRepository;
use App\Service\PredictionSaveService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class PredictionSaveServiceTest extends TestCase
{
    public function testCreatesPredictionWithValidScores(): void
    {
        $user = (new User())->setName('Test')->setEmail('t@test.com');
        $fixture = $this->createFixture();

        $repository = $this->createMock(PredictionRepository::class);
        $repository->method('findOneByUserAndFixture')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(Prediction::class));
        $entityManager->expects(self::once())->method('flush');

        $service = new PredictionSaveService($repository, $entityManager);
        $result = $service->save($user, $fixture, 2, 1);

        self::assertTrue($result['ok']);
    }

    public function testRejectsInvalidScores(): void
    {
        $user = new User();
        $fixture = $this->createFixture();

        $service = new PredictionSaveService(
            $this->createMock(PredictionRepository::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $result = $service->save($user, $fixture, -1, 0);

        self::assertFalse($result['ok']);
    }

    private function createFixture(): Fixture
    {
        return (new Fixture())
            ->setHomeTeam((new Team())->setCode('AAA')->setName('A'))
            ->setAwayTeam((new Team())->setCode('BBB')->setName('B'))
            ->setKickoffAt(new \DateTimeImmutable('2026-06-22 18:00:00', new \DateTimeZone('UTC')));
    }
}
