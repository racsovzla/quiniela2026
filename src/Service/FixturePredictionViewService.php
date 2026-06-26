<?php

namespace App\Service;

use App\Entity\Fixture;
use App\Entity\Prediction;

/**
 * Construye la "vista" de un partido para el usuario: estado (abierta / en vivo /
 * finalizada / cerrada), marcador real y puntos obtenidos. Lo comparten la página
 * de inicio ("Partidos de hoy") y la de predicciones, para no duplicar la lógica.
 */
class FixturePredictionViewService
{
    public function __construct(
        private readonly PredictionWindowService $windowService,
        private readonly ScoringService $scoringService,
    ) {
    }

    /**
     * @return array{
     *     state: string,
     *     statusLabel: string,
     *     statusClass: string,
     *     canEdit: bool,
     *     deadlineIso: string,
     *     scoreboardText: ?string,
     *     pointsText: ?string,
     *     pointsClass: string,
     *     countsNote: ?string
     * }
     */
    public function build(
        Fixture $fixture,
        ?Prediction $prediction,
        \DateTimeImmutable $nowUtc,
        ?\DateTimeImmutable $paymentValidatedAt,
    ): array {
        $hasScore = $fixture->hasFinalScore();
        $finished = $fixture->getStatus() === Fixture::STATUS_FINISHED;
        $isLive = !$finished && $hasScore && $fixture->getKickoffAt() <= $nowUtc;
        $canEdit = $this->windowService->canEditAt($fixture, $nowUtc);

        $state = match (true) {
            $finished => 'finished',
            $isLive => 'live',
            $canEdit => 'open',
            default => 'closed',
        };

        [$statusLabel, $statusClass] = match ($state) {
            'finished' => ['Finalizado', 'text-bg-secondary'],
            'live' => ['EN VIVO', 'text-bg-danger'],
            'open' => ['Abierta', 'text-bg-success'],
            default => ['Cerrada', 'text-bg-warning'],
        };

        return [
            'state' => $state,
            'statusLabel' => $statusLabel,
            'statusClass' => $statusClass,
            'canEdit' => $canEdit,
            'deadlineIso' => $this->windowService->deadline($fixture)->format('c'),
            'scoreboardText' => $this->scoreboardText($fixture, $hasScore),
            ...$this->pointsView($fixture, $prediction, $state, $hasScore, $paymentValidatedAt),
        ];
    }

    /**
     * @return array{pointsText: ?string, pointsClass: string, countsNote: ?string}
     */
    private function pointsView(
        Fixture $fixture,
        ?Prediction $prediction,
        string $state,
        bool $hasScore,
        ?\DateTimeImmutable $paymentValidatedAt,
    ): array {
        if ($state !== 'live' && $state !== 'finished') {
            return ['pointsText' => null, 'pointsClass' => '', 'countsNote' => null];
        }

        if (!$prediction || !$hasScore) {
            return ['pointsText' => 'No cargaste predicción', 'pointsClass' => 'text-body-secondary', 'countsNote' => null];
        }

        $points = $this->scoringService->pointsForPrediction($prediction);

        if ($state === 'finished') {
            $pointsText = sprintf('🏆 Obtuviste: %d %s', $points, $points === 1 ? 'punto' : 'puntos');
            $pointsClass = 'text-success fw-semibold';
        } else {
            $pointsText = sprintf('⚡ Puntos provisionales: +%d (puede cambiar)', $points);
            $pointsClass = 'text-info fw-semibold';
        }

        $counts = $paymentValidatedAt !== null && $fixture->getKickoffAt() >= $paymentValidatedAt;

        return [
            'pointsText' => $pointsText,
            'pointsClass' => $pointsClass,
            'countsNote' => $counts ? null : 'No suma todavía: pago pendiente de validación.',
        ];
    }

    private function scoreboardText(Fixture $fixture, bool $hasScore): ?string
    {
        if (!$hasScore) {
            return null;
        }

        $text = sprintf('%d - %d', $fixture->getHomeScore(), $fixture->getAwayScore());
        if ($fixture->wentToPenalties()) {
            $text .= sprintf(' (%d - %d pen.)', $fixture->getPenaltyHomeScore(), $fixture->getPenaltyAwayScore());
        }

        return $text;
    }
}
