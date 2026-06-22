<?php

namespace App\Tests\Entity;

use App\Entity\Fixture;
use PHPUnit\Framework\TestCase;

class FixtureTest extends TestCase
{
    public function testStatusLabels(): void
    {
        $fixture = new Fixture();

        $fixture->setStatus(Fixture::STATUS_POSTPONED);
        self::assertSame('Retrasado', $fixture->getStatusLabel());
        self::assertTrue($fixture->isDelayed());
        self::assertFalse($fixture->isPotentiallyLive());

        $fixture->setStatus(Fixture::STATUS_RESCHEDULED);
        self::assertSame('Reprogramado', $fixture->getStatusLabel());
        self::assertTrue($fixture->isPotentiallyLive());
    }

    public function testApplyRescheduleReset(): void
    {
        $fixture = (new Fixture())
            ->setKickoffAt(new \DateTimeImmutable('2026-06-22 18:00:00', new \DateTimeZone('UTC')))
            ->setStatus(Fixture::STATUS_POSTPONED)
            ->setHomeScore(1)
            ->setAwayScore(0)
            ->setPredictionsEmailSentAt(new \DateTimeImmutable('2026-06-22 17:00:00', new \DateTimeZone('UTC')));

        $newKickoff = new \DateTimeImmutable('2026-06-25 20:00:00', new \DateTimeZone('UTC'));
        $fixture->applyRescheduleReset($newKickoff);

        self::assertSame(Fixture::STATUS_RESCHEDULED, $fixture->getStatus());
        self::assertSame($newKickoff, $fixture->getKickoffAt());
        self::assertNull($fixture->getHomeScore());
        self::assertNull($fixture->getPredictionsEmailSentAt());
    }

    public function testDelayedFixturesStayEditableAfterKickoff(): void
    {
        $fixture = (new Fixture())
            ->setKickoffAt(new \DateTimeImmutable('2026-06-22 18:00:00', new \DateTimeZone('UTC')))
            ->setStatus(Fixture::STATUS_POSTPONED);

        $now = new \DateTimeImmutable('2026-06-22 20:00:00', new \DateTimeZone('UTC'));

        self::assertTrue($fixture->isEditableAt($now));
    }
}
