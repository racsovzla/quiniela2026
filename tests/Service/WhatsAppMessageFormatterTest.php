<?php

namespace App\Tests\Service;

use App\Service\WhatsAppMessageFormatter;
use PHPUnit\Framework\TestCase;

class WhatsAppMessageFormatterTest extends TestCase
{
    public function testFormatFixturePredictionsClosedBuildsRichMessage(): void
    {
        $formatter = new WhatsAppMessageFormatter();

        $message = $formatter->formatFixturePredictionsClosed(
            'Brasil',
            'Haití',
            new \DateTimeImmutable('2026-06-20 00:30:00', new \DateTimeZone('UTC')),
            [
                ['name' => 'Oscar', 'homeScore' => 4, 'awayScore' => 0],
                ['name' => 'Pedro', 'homeScore' => 3, 'awayScore' => 0],
            ],
        );

        self::assertNotNull($message);
        self::assertStringContainsString('⚽ *Quiniela 2026*', $message);
        self::assertStringContainsString('*Brasil* vs *Haití*', $message);
        self::assertStringContainsString('📅 20/06/2026 00:30 UTC', $message);
        self::assertStringContainsString('Oscar → *4-0*', $message);
        self::assertStringContainsString('_2 participantes en juego_', $message);
    }

    public function testFormatMissingPredictionsReminderBuildsRichMessage(): void
    {
        $formatter = new WhatsAppMessageFormatter();

        $message = $formatter->formatMissingPredictionsReminder('Argentina', 'Brasil', ['Pedro']);

        self::assertStringContainsString('⚠️ *¡Atención, quiniela!*', $message);
        self::assertStringContainsString('*Argentina* vs *Brasil*', $message);
        self::assertStringContainsString('• Pedro', $message);
        self::assertStringContainsString('Falta 1 persona', $message);
    }
}
