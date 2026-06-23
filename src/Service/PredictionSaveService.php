<?php

namespace App\Service;

use App\Entity\Fixture;
use App\Entity\Prediction;
use App\Entity\User;
use App\Repository\PredictionRepository;
use Doctrine\ORM\EntityManagerInterface;

class PredictionSaveService
{
    public function __construct(
        private readonly PredictionRepository $predictionRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{ok: true}|array{ok: false, error: string}
     */
    public function save(
        User $user,
        Fixture $fixture,
        mixed $homeRaw,
        mixed $awayRaw,
        mixed $penaltyHomeRaw = null,
        mixed $penaltyAwayRaw = null,
    ): array {
        $home = filter_var($homeRaw, FILTER_VALIDATE_INT);
        $away = filter_var($awayRaw, FILTER_VALIDATE_INT);

        if ($home === false || $away === false || $home < 0 || $away < 0) {
            return ['ok' => false, 'error' => 'Marcador inválido. Debe ser entero mayor o igual a 0.'];
        }

        $penaltyHome = null;
        $penaltyAway = null;

        if ($fixture->isKnockout() && $home === $away) {
            $penaltyHome = filter_var($penaltyHomeRaw, FILTER_VALIDATE_INT);
            $penaltyAway = filter_var($penaltyAwayRaw, FILTER_VALIDATE_INT);

            if ($penaltyHome === false || $penaltyAway === false || $penaltyHome < 0 || $penaltyAway < 0) {
                return ['ok' => false, 'error' => 'En eliminatorias con empate debes indicar el marcador de penales.'];
            }

            if ($penaltyHome === $penaltyAway) {
                return ['ok' => false, 'error' => 'Los penales deben tener un ganador (no puede ser empate).'];
            }
        }

        $prediction = $this->predictionRepository->findOneByUserAndFixture($user, $fixture);
        if (!$prediction instanceof Prediction) {
            $prediction = (new Prediction())
                ->setUser($user)
                ->setFixture($fixture);
            $this->entityManager->persist($prediction);
        }

        $prediction
            ->setPredictedHomeScore((int) $home)
            ->setPredictedAwayScore((int) $away)
            ->setPredictedPenaltyHomeScore($penaltyHome !== null ? (int) $penaltyHome : null)
            ->setPredictedPenaltyAwayScore($penaltyAway !== null ? (int) $penaltyAway : null);

        $this->entityManager->flush();

        return ['ok' => true];
    }
}
