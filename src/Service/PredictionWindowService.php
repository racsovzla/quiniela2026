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
        return $fixture->isEditableAt($nowUtc);
    }

    public function deadline(Fixture $fixture): \DateTimeImmutable
    {
        return $fixture->getKickoffAt()->modify('-5 minutes');
    }
}
