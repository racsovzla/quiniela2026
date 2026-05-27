<?php

namespace App\Service;

use App\Entity\Fixture;
use App\Entity\Prediction;
use App\Entity\User;
use App\Repository\FixtureRepository;
use App\Repository\PredictionRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

class SimulationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly FixtureRepository $fixtureRepository,
        private readonly PredictionRepository $predictionRepository,
    ) {
    }

    /**
     * @return array{createdUsers:int, createdPredictions:int, totalUsers:int}
     */
    public function seedUsersAndPredictions(int $targetUsers = 10): array
    {
        $createdUsers = 0;
        $createdPredictions = 0;
        $users = [];

        for ($i = 1; $i <= $targetUsers; ++$i) {
            $email = sprintf('sim%02d@quiniela.test', $i);
            $user = $this->userRepository->findOneBy(['email' => $email]);

            if (!$user instanceof User) {
                $user = (new User())
                    ->setName(sprintf('Sim User %02d', $i))
                    ->setEmail($email)
                    ->setRoles(['ROLE_USER'])
                    ->setIsVerified(true)
                        ->setIsApproved(true)
                        ->setPaymentValidatedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                    ->setPassword(null);
                $this->entityManager->persist($user);
                ++$createdUsers;
            }

            $users[] = $user;
        }

        $fixtures = $this->fixtureRepository->findAllOrdered();

        foreach ($users as $user) {
            foreach ($fixtures as $fixture) {
                $existing = $this->predictionRepository->findOneByUserAndFixture($user, $fixture);
                if ($existing instanceof Prediction) {
                    continue;
                }

                $prediction = (new Prediction())
                    ->setUser($user)
                    ->setFixture($fixture)
                    ->setPredictedHomeScore(random_int(0, 4))
                    ->setPredictedAwayScore(random_int(0, 4));

                $this->entityManager->persist($prediction);
                ++$createdPredictions;
            }
        }

        $this->entityManager->flush();

        return [
            'createdUsers' => $createdUsers,
            'createdPredictions' => $createdPredictions,
            'totalUsers' => $targetUsers,
        ];
    }

    public function simulateNextFixtureScore(): ?Fixture
    {
        $fixture = $this->fixtureRepository->findNextScheduledFixture();

        if (!$fixture instanceof Fixture) {
            return null;
        }

        $fixture
            ->setHomeScore(random_int(0, 5))
            ->setAwayScore(random_int(0, 5));

        $this->entityManager->flush();

        return $fixture;
    }

    public function finalizeInProgressFixture(): ?Fixture
    {
        $fixture = $this->fixtureRepository->findLatestScheduledFixtureWithScore();

        if (!$fixture instanceof Fixture) {
            return null;
        }

        $fixture->setStatus(Fixture::STATUS_FINISHED);
        $this->entityManager->flush();

        return $fixture;
    }
}