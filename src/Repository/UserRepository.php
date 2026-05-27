<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function save(User $user, bool $flush = false): void
    {
        $this->getEntityManager()->persist($user);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findByGoogleId(string $googleId): ?User
    {
        return $this->findOneBy(['googleId' => $googleId]);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    /**
     * @return list<User>
     */
    public function findApprovedVerifiedRecipients(): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.isApproved = true')
            ->andWhere('u.isVerified = true')
            ->addOrderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
