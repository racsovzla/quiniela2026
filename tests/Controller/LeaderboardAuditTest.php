<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class LeaderboardAuditTest extends WebTestCase
{
    private array $createdUsers = [];

    protected function tearDown(): void
    {
        if ($this->createdUsers) {
            $em = self::getContainer()->get(EntityManagerInterface::class);
            foreach ($this->createdUsers as $user) {
                $userFromDb = $em->getRepository(User::class)->find($user->getId());
                if ($userFromDb) {
                    $em->remove($userFromDb);
                }
            }
            $em->flush();
            $em->clear();
        }
        parent::tearDown();
    }

    public function testAnonymousAccessIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/leaderboard/user/999/audit');
        self::assertResponseRedirects('/login');
    }

    public function testRegularUserAccessIsSuccessful(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'user_audit@example.com', ['ROLE_USER']);
        $client->loginUser($user);

        $client->request('GET', sprintf('/leaderboard/user/%d/audit', $user->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.text-secondary', 'Suma auditada');
    }

    public function testOldAuditPathReturnsNotFound(): void
    {
        $client = static::createClient();
        $admin = $this->createUser($client, 'admin_audit@example.com', ['ROLE_ADMIN']);
        $client->loginUser($admin);

        $client->request('GET', '/admin/leaderboard/audit');
        self::assertResponseStatusCodeSame(404);
    }

    private function createUser($client, string $email, array $roles): User
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $user = (new User())
            ->setEmail($email)
            ->setName('Test ' . implode(' ', $roles))
            ->setRoles($roles)
            ->setIsApproved(true)
            ->setIsVerified(true)
            ->setPassword('somepasswordhash');

        $em->persist($user);
        $em->flush();
        $this->createdUsers[] = $user;

        return $user;
    }
}
