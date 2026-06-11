<?php

namespace App\Repository;

use App\Entity\Fixture;
use App\Entity\Prediction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Prediction>
 */
class PredictionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Prediction::class);
    }

    public function findOneByUserAndFixture(User $user, Fixture $fixture): ?Prediction
    {
        return $this->findOneBy(['user' => $user, 'fixture' => $fixture]);
    }

    /**
     * @return list<Prediction>
     */
    public function findByFixtureWithUser(Fixture $fixture): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.user', 'u')->addSelect('u')
            ->leftJoin('p.fixture', 'f')->addSelect('f')
            ->leftJoin('f.homeTeam', 'ht')->addSelect('ht')
            ->leftJoin('f.awayTeam', 'at')->addSelect('at')
            ->andWhere('p.fixture = :fixture')
            ->setParameter('fixture', $fixture)
            ->addOrderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Prediction>
     */
    public function findByUserWithFixture(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.fixture', 'f')->addSelect('f')
            ->leftJoin('f.homeTeam', 'ht')->addSelect('ht')
            ->leftJoin('f.awayTeam', 'at')->addSelect('at')
            ->andWhere('p.user = :user')
            ->setParameter('user', $user)
            ->orderBy('f.kickoffAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Predictions visible on leaderboard: edit window closed (kickoff - 5 min) or fixture finished.
     * Loaded scores before kickoff do not reveal predictions.
     *
     * @return list<Prediction>
     */
    public function findClosedWithFixtureAndUserOrderedForGroups(\DateTimeImmutable $nowUtc): array
    {
        $closingThreshold = $nowUtc->modify('+5 minutes');

        return $this->createQueryBuilder('p')
            ->leftJoin('p.user', 'u')->addSelect('u')
            ->leftJoin('p.fixture', 'f')->addSelect('f')
            ->leftJoin('f.group', 'g')->addSelect('g')
            ->leftJoin('f.homeTeam', 'ht')->addSelect('ht')
            ->leftJoin('f.awayTeam', 'at')->addSelect('at')
            ->andWhere('f.kickoffAt <= :closingThreshold OR f.status = :finishedStatus')
            ->setParameter('closingThreshold', $closingThreshold)
            ->setParameter('finishedStatus', Fixture::STATUS_FINISHED)
            ->addOrderBy('g.code', 'ASC')
            ->addOrderBy('f.status', 'DESC') // 'scheduled' comes before 'finished' alphabetically when sorting descending
            ->addOrderBy('f.kickoffAt', 'ASC')
            ->addOrderBy('f.id', 'ASC')
            ->addOrderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Prediction>
     */
    public function findInProgressWithLoadedScoresForLivePoints(\DateTimeImmutable $nowUtc): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.user', 'u')->addSelect('u')
            ->leftJoin('p.fixture', 'f')->addSelect('f')
            ->andWhere('f.status = :scheduledStatus')
            ->andWhere('f.homeScore IS NOT NULL AND f.awayScore IS NOT NULL')
            ->andWhere('f.kickoffAt <= :now OR (f.homeScore IS NOT NULL AND f.awayScore IS NOT NULL)')
            ->setParameter('scheduledStatus', Fixture::STATUS_SCHEDULED)
            ->setParameter('now', $nowUtc)
            ->addOrderBy('f.kickoffAt', 'ASC')
            ->addOrderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Prediction>
     */
    public function findFinishedBetweenKickoff(\DateTimeImmutable $fromUtc, \DateTimeImmutable $toUtc): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.user', 'u')->addSelect('u')
            ->leftJoin('p.fixture', 'f')->addSelect('f')
            ->andWhere('f.status = :finishedStatus')
            ->andWhere('f.homeScore IS NOT NULL AND f.awayScore IS NOT NULL')
            ->andWhere('f.kickoffAt >= :fromUtc')
            ->andWhere('f.kickoffAt <= :toUtc')
            ->setParameter('finishedStatus', Fixture::STATUS_FINISHED)
            ->setParameter('fromUtc', $fromUtc)
            ->setParameter('toUtc', $toUtc)
            ->addOrderBy('u.name', 'ASC')
            ->addOrderBy('f.kickoffAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Prediction>
     */
    public function findFinishedOrderedByUserAndKickoffDesc(): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.user', 'u')->addSelect('u')
            ->leftJoin('p.fixture', 'f')->addSelect('f')
            ->andWhere('f.status = :finishedStatus')
            ->andWhere('f.homeScore IS NOT NULL AND f.awayScore IS NOT NULL')
            ->setParameter('finishedStatus', Fixture::STATUS_FINISHED)
            ->addOrderBy('u.id', 'ASC')
            ->addOrderBy('f.kickoffAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
