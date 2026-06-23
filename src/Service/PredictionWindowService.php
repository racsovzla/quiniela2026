<?php

namespace App\Service;

use App\Entity\Fixture;

class PredictionWindowService
{
    public function canEdit(Fixture $fixture): bool
    {
        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->canEditAt($fixture, $nowUtc);
    }

    public function canEditAt(Fixture $fixture, \DateTimeImmutable $nowUtc): bool
    {
        $deadline = $this->deadline($fixture);

        return $nowUtc < $deadline;
    }

    public function deadline(Fixture $fixture): \DateTimeImmutable
    {
        return $fixture->getKickoffAt()->modify('-5 minutes');
    }
}
