<?php

namespace App\Repository;

use App\Entity\Fixture;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Fixture>
 */
class FixtureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Fixture::class);
    }

    /**
     * @return list<Fixture>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.homeTeam', 'ht')->addSelect('ht')
            ->leftJoin('f.awayTeam', 'at')->addSelect('at')
            ->orderBy('f.kickoffAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Fixture>
     */
    public function findAllOrderedForAdmin(): array
    {
        $fixtures = $this->createQueryBuilder('f')
            ->leftJoin('f.homeTeam', 'ht')->addSelect('ht')
            ->leftJoin('f.awayTeam', 'at')->addSelect('at')
            ->getQuery()
            ->getResult();

        usort($fixtures, function (Fixture $a, Fixture $b) {
            if ($a->getStatus() !== $b->getStatus()) {
                return $a->getStatus() === Fixture::STATUS_SCHEDULED ? -1 : 1;
            }
            if ($a->getStatus() === Fixture::STATUS_SCHEDULED) {
                return $a->getKickoffAt() <=> $b->getKickoffAt();
            }
            return $b->getKickoffAt() <=> $a->getKickoffAt();
        });

        return $fixtures;
    }

    /**
     * @return list<Fixture>
     */
    public function findFinishedOrdered(): array
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.homeTeam', 'ht')->addSelect('ht')
            ->leftJoin('f.awayTeam', 'at')->addSelect('at')
            ->leftJoin('f.group', 'g')->addSelect('g')
            ->andWhere('f.status = :status')
            ->andWhere('f.homeScore IS NOT NULL AND f.awayScore IS NOT NULL')
            ->setParameter('status', Fixture::STATUS_FINISHED)
            ->orderBy('f.kickoffAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Fixture>
     */
    public function findAllOrderedByGroupAndKickoff(): array
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.homeTeam', 'ht')->addSelect('ht')
            ->leftJoin('f.awayTeam', 'at')->addSelect('at')
            ->leftJoin('f.group', 'g')->addSelect('g')
            ->addOrderBy('g.code', 'ASC')
            ->addOrderBy('f.kickoffAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findNextScheduledFixture(): ?Fixture
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.homeTeam', 'ht')->addSelect('ht')
            ->leftJoin('f.awayTeam', 'at')->addSelect('at')
            ->andWhere('f.status = :status')
            ->setParameter('status', Fixture::STATUS_SCHEDULED)
            ->orderBy('f.kickoffAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findInProgressScheduledFixture(\DateTimeImmutable $nowUtc): ?Fixture
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.homeTeam', 'ht')->addSelect('ht')
            ->leftJoin('f.awayTeam', 'at')->addSelect('at')
            ->andWhere('f.status = :status')
            ->andWhere('f.kickoffAt <= :now')
            ->setParameter('status', Fixture::STATUS_SCHEDULED)
            ->setParameter('now', $nowUtc)
            ->orderBy('f.kickoffAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestScheduledFixtureWithScore(): ?Fixture
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.homeTeam', 'ht')->addSelect('ht')
            ->leftJoin('f.awayTeam', 'at')->addSelect('at')
            ->andWhere('f.status = :status')
            ->andWhere('f.homeScore IS NOT NULL AND f.awayScore IS NOT NULL')
            ->setParameter('status', Fixture::STATUS_SCHEDULED)
            ->orderBy('f.kickoffAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Scheduled fixtures whose kickoff has passed (in progress or pending catch-up).
     *
     * @return list<Fixture>
     */
    public function findScheduledPotentiallyLive(\DateTimeImmutable $nowUtc): array
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.homeTeam', 'ht')->addSelect('ht')
            ->leftJoin('f.awayTeam', 'at')->addSelect('at')
            ->andWhere('f.status = :status')
            ->andWhere('f.kickoffAt <= :now')
            ->setParameter('status', Fixture::STATUS_SCHEDULED)
            ->setParameter('now', $nowUtc)
            ->orderBy('f.kickoffAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Fixtures whose prediction window closed (kickoff - 5 min) and email not sent yet.
     *
     * @return list<Fixture>
     */
    public function findDueForPredictionEmail(
        \DateTimeImmutable $nowUtc,
        \DateTimeImmutable $catchUpSinceUtc,
    ): array {
        $closingThreshold = $nowUtc->modify('+5 minutes');

        return $this->createQueryBuilder('f')
            ->leftJoin('f.homeTeam', 'ht')->addSelect('ht')
            ->leftJoin('f.awayTeam', 'at')->addSelect('at')
            ->andWhere('f.predictionsEmailSentAt IS NULL')
            ->andWhere('f.kickoffAt <= :closingThreshold')
            ->andWhere('f.kickoffAt >= :catchUpSinceUtc')
            ->setParameter('closingThreshold', $closingThreshold)
            ->setParameter('catchUpSinceUtc', $catchUpSinceUtc)
            ->orderBy('f.kickoffAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find the next fixture whose prediction editing window has not closed yet.
     */
    public function findNextEditableFixture(\DateTimeImmutable $nowUtc): ?Fixture
    {
        $closingThreshold = $nowUtc->modify('+5 minutes');

        return $this->createQueryBuilder('f')
            ->leftJoin('f.homeTeam', 'ht')->addSelect('ht')
            ->leftJoin('f.awayTeam', 'at')->addSelect('at')
            ->andWhere('f.status = :status')
            ->andWhere('f.kickoffAt > :closingThreshold')
            ->setParameter('status', Fixture::STATUS_SCHEDULED)
            ->setParameter('closingThreshold', $closingThreshold)
            ->orderBy('f.kickoffAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}

