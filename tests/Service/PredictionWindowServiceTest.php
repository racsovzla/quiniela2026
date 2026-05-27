<?php

namespace App\Tests\Service;

use App\Entity\Fixture;
use App\Service\PredictionWindowService;
use PHPUnit\Framework\TestCase;

class PredictionWindowServiceTest extends TestCase
{
    public function testCanEditAtUsesFiveMinuteDeadline(): void
    {
        $service = new PredictionWindowService();
        $fixture = (new Fixture())->setKickoffAt(new \DateTimeImmutable('2026-06-11 19:00:00', new \DateTimeZone('UTC')));

        $beforeDeadline = new \DateTimeImmutable('2026-06-11 18:54:59', new \DateTimeZone('UTC'));
        $atDeadline = new \DateTimeImmutable('2026-06-11 18:55:00', new \DateTimeZone('UTC'));

        self::assertTrue($service->canEditAt($fixture, $beforeDeadline));
        self::assertFalse($service->canEditAt($fixture, $atDeadline));
    }
}
