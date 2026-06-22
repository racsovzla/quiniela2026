<?php

namespace App\Service;

class WhatsAppMessageFormatter
{
    /**
     * @param list<array{name: string, homeScore: int, awayScore: int}> $summaryRows
     */
    public function formatFixturePredictionsClosed(
        string $homeTeamName,
        string $awayTeamName,
        ?\DateTimeImmutable $kickoffAt,
        array $summaryRows,
        string $prefix = '',
    ): ?string {
        if ($summaryRows === []) {
            return null;
        }

        $lines = [];

        if ($prefix !== '') {
            $lines[] = rtrim($prefix);
            $lines[] = '';
        }

        $lines[] = '⚽ *Quiniela 2026*';
        $lines[] = '🔒 *Ventana cerrada* — ya no se pueden editar pronósticos';
        $lines[] = '';
        $lines[] = sprintf('*%s* vs *%s*', $homeTeamName, $awayTeamName);

        if ($kickoffAt instanceof \DateTimeImmutable) {
            $lines[] = sprintf('📅 %s UTC', $kickoffAt->format('d/m/Y H:i'));
        }

        $lines[] = '';
        $lines[] = '📋 *Pronósticos registrados:*';

        foreach ($summaryRows as $row) {
            $lines[] = sprintf(
                '• %s → *%d-%d*',
                $row['name'],
                $row['homeScore'],
                $row['awayScore'],
            );
        }

        $participantCount = count($summaryRows);
        $lines[] = '';
        $lines[] = sprintf(
            '_%d %s en juego_',
            $participantCount,
            $participantCount === 1 ? 'participante' : 'participantes',
        );

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $missingUserNames
     */
    public function formatMissingPredictionsReminder(
        string $homeTeamName,
        string $awayTeamName,
        array $missingUserNames,
    ): string {
        $lines = [
            '⚠️ *¡Atención, quiniela!*',
            '',
            sprintf('El próximo partido es *%s* vs *%s*', $homeTeamName, $awayTeamName),
            '',
            '*Aún no han pronosticado:*',
        ];

        foreach ($missingUserNames as $name) {
            $lines[] = '• '.$name;
        }

        $missingCount = count($missingUserNames);
        $lines[] = '';
        $lines[] = sprintf(
            '⏰ %s — ¡corre a registrar tu marcador!',
            $missingCount === 1 ? 'Falta 1 persona' : sprintf('Faltan %d personas', $missingCount),
        );

        return implode("\n", $lines);
    }

    public function formatNewFixtureAvailable(
        string $phaseName,
        string $homeTeamName,
        string $awayTeamName,
        \DateTimeImmutable $kickoffAt,
    ): string {
        return implode("\n", [
            '🆕 *Nuevo partido en quiniela*',
            '',
            sprintf('*%s*', $phaseName),
            sprintf('*%s* vs *%s*', $homeTeamName, $awayTeamName),
            sprintf('📅 %s UTC', $kickoffAt->format('d/m/Y H:i')),
        ]);
    }
}
