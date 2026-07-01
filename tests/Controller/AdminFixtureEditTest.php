<?php

namespace App\Tests\Controller;

use App\Entity\Fixture;
use App\Entity\Team;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminFixtureEditTest extends WebTestCase
{
    private array $created = [];

    protected function tearDown(): void
    {
        if ($this->created) {
            $em = self::getContainer()->get(EntityManagerInterface::class);
            foreach ($this->created as $entity) {
                $managed = $em->getRepository($entity::class)->find($entity->getId());
                if ($managed) {
                    $em->remove($managed);
                }
            }
            $em->flush();
        }
        parent::tearDown();
    }

    public function testAdminCanEditFixtureScores(): void
    {
        $client = static::createClient();
        $fixture = $this->createFixture();
        $admin = $this->createAdmin($client);

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/fixtures/'.$fixture->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('input[name="fixture[_token]"]')->count());

        $form = $crawler->selectButton('Guardar Partido')->form();
        $form['fixture[homeScore]'] = '3';
        $form['fixture[awayScore]'] = '1';
        $form['fixture[status]'] = Fixture::STATUS_FINISHED;

        $client->submit($form);
        self::assertResponseRedirects('/admin');

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $updated = $em->getRepository(Fixture::class)->find($fixture->getId());
        self::assertSame(3, $updated->getHomeScore());
        self::assertSame(1, $updated->getAwayScore());
        self::assertSame(Fixture::STATUS_FINISHED, $updated->getStatus());
    }

    private function createFixture(): Fixture
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $home = (new Team())->setName('Home '.uniqid('', true))->setCode('H'.random_int(10, 99));
        $away = (new Team())->setName('Away '.uniqid('', true))->setCode('A'.random_int(10, 99));
        $em->persist($home);
        $em->persist($away);

        $fixture = (new Fixture())
            ->setHomeTeam($home)
            ->setAwayTeam($away)
            ->setKickoffAt(new \DateTimeImmutable('2026-07-01 20:00:00', new \DateTimeZone('UTC')))
            ->setStatus(Fixture::STATUS_SCHEDULED);

        $em->persist($fixture);
        $em->flush();

        $this->created[] = $fixture;
        $this->created[] = $home;
        $this->created[] = $away;

        return $fixture;
    }

    private function createAdmin($client): User
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $user = (new User())
            ->setEmail('admin_fixture_'.uniqid('', true).'@example.com')
            ->setName('Admin')
            ->setRoles(['ROLE_ADMIN'])
            ->setIsApproved(true)
            ->setIsVerified(true)
            ->setPassword('hash');

        $em->persist($user);
        $em->flush();
        $this->created[] = $user;

        return $user;
    }
}
