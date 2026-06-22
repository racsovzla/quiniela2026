<?php

namespace App\Tests\Controller;

use App\Entity\Fixture;
use App\Entity\Prediction;
use App\Entity\Team;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class HomeTodayMatchesTest extends WebTestCase
{
    /** @var list<object> */
    private array $created = [];

    protected function tearDown(): void
    {
        if ($this->created) {
            $em = self::getContainer()->get(EntityManagerInterface::class);
            // Remove in dependency order: predictions -> fixtures -> teams/users.
            foreach ($this->created as $entity) {
                $managed = $em->getRepository($entity::class)->find($entity->getId());
                if ($managed) {
                    $em->remove($managed);
                }
            }
            $em->flush();
            $em->clear();
        }

        parent::tearDown();
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/home/today-matches');
        self::assertResponseRedirects('/login');
    }

    public function testFinishedFixtureShowsEarnedPoints(): void
    {
        $client = static::createClient();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $user = $this->createUser($client, $now->modify('-7 days'));
        $fixture = $this->createFixture($client, $this->kickoffWithinWindow($now, '-2 hours'), Fixture::STATUS_FINISHED, 2, 1);
        $this->createPrediction($client, $user, $fixture, 2, 1); // exact -> 3 points

        $client->loginUser($user);
        $client->request('GET', '/home/today-matches?tz=UTC');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-fixture-id="'.$fixture->getId().'"', $client->getResponse()->getContent());
        self::assertSelectorTextContains('[data-fixture-id="'.$fixture->getId().'"] [data-role="status-badge"]', 'Finalizado');
        self::assertSelectorTextContains('[data-fixture-id="'.$fixture->getId().'"] [data-role="points"]', 'Obtuviste: 3 puntos');
    }

    public function testLiveFixtureShowsProvisionalPoints(): void
    {
        $client = static::createClient();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $user = $this->createUser($client, $now->modify('-7 days'));
        // scheduled + kickoff in the past + score loaded => live
        $fixture = $this->createFixture($client, $this->kickoffWithinWindow($now, '-30 minutes'), Fixture::STATUS_SCHEDULED, 1, 0);
        $this->createPrediction($client, $user, $fixture, 1, 0); // exact -> 3 provisional points

        $client->loginUser($user);
        $client->request('GET', '/home/today-matches?tz=UTC');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-fixture-id="'.$fixture->getId().'"] [data-role="status-badge"]', 'EN VIVO');
        self::assertSelectorTextContains('[data-fixture-id="'.$fixture->getId().'"] [data-role="points"]', 'Puntos provisionales: +3');
    }

    public function testLiveJsonReturnsProvisionalPoints(): void
    {
        $client = static::createClient();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $user = $this->createUser($client, $now->modify('-7 days'));
        $fixture = $this->createFixture($client, $this->kickoffWithinWindow($now, '-30 minutes'), Fixture::STATUS_SCHEDULED, 2, 2);
        $this->createPrediction($client, $user, $fixture, 1, 0); // wrong result -> 0 points

        $client->loginUser($user);
        $client->request('GET', '/home/today-matches/live.json?tz=UTC');

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $entry = null;
        foreach ($data['fixtures'] as $f) {
            if ($f['id'] === $fixture->getId()) {
                $entry = $f;
                break;
            }
        }

        self::assertNotNull($entry, 'Live fixture should be present in JSON payload');
        self::assertSame('EN VIVO', $entry['statusLabel']);
        self::assertSame('2 - 2', $entry['scoreboardText']);
        self::assertStringContainsString('Puntos provisionales: +0', $entry['pointsText']);
    }

    public function testPostponedFixtureShowsRetrasadoBadge(): void
    {
        $client = static::createClient();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $user = $this->createUser($client, $now->modify('-7 days'));
        $fixture = $this->createFixture(
            $client,
            $this->kickoffWithinWindow($now, '-2 hours'),
            Fixture::STATUS_POSTPONED,
            null,
            null,
        );
        $this->createPrediction($client, $user, $fixture, 2, 1);

        $client->loginUser($user);
        $client->request('GET', '/home/today-matches?tz=UTC');

        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();
        self::assertStringContainsString('data-fixture-id="'.$fixture->getId().'"', $content);
        self::assertSelectorTextContains('[data-fixture-id="'.$fixture->getId().'"] [data-role="status-badge"]', 'Retrasado');
        self::assertSelectorTextContains('[data-fixture-id="'.$fixture->getId().'"] [data-role="scoreboard"]', 'vs');
        self::assertStringNotContainsString('EN VIVO', (string) $content);
    }

    /**
     * Returns a kickoff that is both in the past and inside the current calendar day
     * ([today 00:00, tomorrow 00:00) in UTC, matching the controller for ?tz=UTC), so the
     * fixture is never excluded when the suite runs shortly after midnight UTC.
     */
    private function kickoffWithinWindow(\DateTimeImmutable $now, string $offset): \DateTimeImmutable
    {
        $startOfDay = $now->setTime(0, 0, 0);
        $candidate = $now->modify($offset);

        return $candidate < $startOfDay ? $startOfDay : $candidate;
    }

    private function createUser(KernelBrowser $client, \DateTimeImmutable $paymentValidatedAt): User
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $user = (new User())
            ->setEmail('home_'.uniqid('', true).'@example.com')
            ->setName('Home Tester')
            ->setRoles(['ROLE_USER'])
            ->setIsApproved(true)
            ->setIsVerified(true)
            ->setPassword('somepasswordhash')
            ->setPaymentValidatedAt($paymentValidatedAt);

        $em->persist($user);
        $em->flush();
        $this->created[] = $user;

        return $user;
    }

    private function createFixture(KernelBrowser $client, \DateTimeImmutable $kickoff, string $status, ?int $home, ?int $away): Fixture
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $homeTeam = (new Team())->setName('Home '.uniqid('', true))->setCode('H'.random_int(10, 99));
        $awayTeam = (new Team())->setName('Away '.uniqid('', true))->setCode('A'.random_int(10, 99));
        $em->persist($homeTeam);
        $em->persist($awayTeam);

        $fixture = (new Fixture())
            ->setHomeTeam($homeTeam)
            ->setAwayTeam($awayTeam)
            ->setKickoffAt($kickoff)
            ->setStatus($status)
            ->setHomeScore($home)
            ->setAwayScore($away);

        $em->persist($fixture);
        $em->flush();

        // Track for cleanup (fixture first so it is removed before its teams).
        $this->created[] = $fixture;
        $this->created[] = $homeTeam;
        $this->created[] = $awayTeam;

        return $fixture;
    }

    private function createPrediction(KernelBrowser $client, User $user, Fixture $fixture, int $home, int $away): Prediction
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $prediction = (new Prediction())
            ->setUser($user)
            ->setFixture($fixture)
            ->setPredictedHomeScore($home)
            ->setPredictedAwayScore($away);

        $em->persist($prediction);
        $em->flush();
        // Predictions must be removed before fixtures/users; prepend.
        array_unshift($this->created, $prediction);

        return $prediction;
    }
}
