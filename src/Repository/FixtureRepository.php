<?php

namespace App\Repository;

use App\Entity\Fixture;
use App\Entity\Team;
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
            $aActive = in_array($a->getStatus(), Fixture::activeStatuses(), true);
            $bActive = in_array($b->getStatus(), Fixture::activeStatuses(), true);
            if ($aActive !== $bActive) {
                return $aActive ? -1 : 1;
            }
            if ($aActive) {
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
            ->andWhere('f.status IN (:statuses)')
            ->setParameter('statuses', [Fixture::STATUS_SCHEDULED, Fixture::STATUS_RESCHEDULED])
            ->orderBy('f.kickoffAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findNextNotStartedFixture(\DateTimeImmutable $nowUtc): ?Fixture
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.homeTeam', 'ht')->addSelect('ht')
            ->leftJoin('f.awayTeam', 'at')->addSelect('at')
            ->andWhere('f.status IN (:statuses)')
            ->andWhere('f.kickoffAt > :now')
            ->setParameter('statuses', [Fixture::STATUS_SCHEDULED, Fixture::STATUS_RESCHEDULED])
            ->setParameter('now', $nowUtc->format('Y-m-d H:i:s'))
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
            ->andWhere('f.status IN (:statuses)')
            ->andWhere('f.kickoffAt <= :now')
            ->setParameter('statuses', Fixture::potentiallyLiveStatuses())
            ->setParameter('now', $nowUtc->format('Y-m-d H:i:s'))
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
            ->andWhere('f.status IN (:statuses)')
            ->andWhere('f.homeScore IS NOT NULL AND f.awayScore IS NOT NULL')
            ->setParameter('statuses', Fixture::potentiallyLiveStatuses())
            ->orderBy('f.kickoffAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Scheduled/rescheduled fixtures whose kickoff has passed (in progress or pending catch-up).
     *
     * @return list<Fixture>
     */
    public function findScheduledPotentiallyLive(\DateTimeImmutable $nowUtc): array
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.homeTeam', 'ht')->addSelect('ht')
            ->leftJoin('f.awayTeam', 'at')->addSelect('at')
            ->andWhere('f.status IN (:statuses)')
            ->andWhere('f.kickoffAt <= :now')
            ->setParameter('statuses', Fixture::potentiallyLiveStatuses())
            ->setParameter('now', $nowUtc->format('Y-m-d H:i:s'))
            ->orderBy('f.kickoffAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Fixtures past kickoff that may need postponed/suspended detection from FIFA.
     *
     * @return list<Fixture>
     */
    public function findOverdueForDelayCheck(\DateTimeImmutable $nowUtc): array
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.homeTeam', 'ht')->addSelect('ht')
            ->leftJoin('f.awayTeam', 'at')->addSelect('at')
            ->andWhere('f.status IN (:statuses)')
            ->andWhere('f.kickoffAt <= :now')
            ->setParameter('statuses', Fixture::potentiallyLiveStatuses())
            ->setParameter('now', $nowUtc->format('Y-m-d H:i:s'))
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
            ->andWhere('f.status IN (:statuses)')
            ->andWhere('f.kickoffAt <= :closingThreshold')
            ->andWhere('f.kickoffAt >= :catchUpSinceUtc')
            ->setParameter('statuses', [Fixture::STATUS_SCHEDULED, Fixture::STATUS_RESCHEDULED])
            ->setParameter('closingThreshold', $closingThreshold->format('Y-m-d H:i:s'))
            ->setParameter('catchUpSinceUtc', $catchUpSinceUtc->format('Y-m-d H:i:s'))
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
            ->andWhere('f.status IN (:statuses)')
            ->andWhere('f.kickoffAt > :closingThreshold')
            ->setParameter('statuses', [Fixture::STATUS_SCHEDULED, Fixture::STATUS_RESCHEDULED])
            ->setParameter('closingThreshold', $closingThreshold->format('Y-m-d H:i:s'))
            ->orderBy('f.kickoffAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<Fixture>
     */
    public function findLatestFinished(int $limit): array
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.homeTeam', 'ht')->addSelect('ht')
            ->leftJoin('f.awayTeam', 'at')->addSelect('at')
            ->andWhere('f.status = :status')
            ->setParameter('status', Fixture::STATUS_FINISHED)
            ->orderBy('f.kickoffAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Fixtures whose kickoff falls within the given UTC range, ordered by kickoff.
     *
     * @return list<Fixture>
     */
    public function findBetween(\DateTimeImmutable $fromUtc, \DateTimeImmutable $toUtc): array
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.homeTeam', 'ht')->addSelect('ht')
            ->leftJoin('f.awayTeam', 'at')->addSelect('at')
            ->leftJoin('f.group', 'g')->addSelect('g')
            ->andWhere('f.kickoffAt >= :fromUtc')
            ->andWhere('f.kickoffAt <= :toUtc')
            ->setParameter('fromUtc', $fromUtc->format('Y-m-d H:i:s'))
            ->setParameter('toUtc', $toUtc->format('Y-m-d H:i:s'))
            ->orderBy('f.kickoffAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Fixture>
     */
    public function findNextScheduled(int $limit): array
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.homeTeam', 'ht')->addSelect('ht')
            ->leftJoin('f.awayTeam', 'at')->addSelect('at')
            ->andWhere('f.status IN (:statuses)')
            ->setParameter('statuses', [Fixture::STATUS_SCHEDULED, Fixture::STATUS_RESCHEDULED, Fixture::STATUS_POSTPONED, Fixture::STATUS_SUSPENDED])
            ->orderBy('f.kickoffAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countActiveFixtures(): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->andWhere('f.status IN (:statuses)')
            ->setParameter('statuses', Fixture::activeStatuses())
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneByFifaMatchId(string $fifaMatchId): ?Fixture
    {
        return $this->findOneBy(['fifaMatchId' => $fifaMatchId]);
    }

    public function findOneByTeamsAndStage(Team $homeTeam, Team $awayTeam, string $stage): ?Fixture
    {
        return $this->findOneBy([
            'homeTeam' => $homeTeam,
            'awayTeam' => $awayTeam,
            'stage' => $stage,
        ]);
    }
}

