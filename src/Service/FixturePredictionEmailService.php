<?php

namespace App\Service;

use App\Entity\Fixture;
use App\Entity\Prediction;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

class FixturePredictionEmailService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Address $mailerFromAddress,
        private readonly CountryNameResolver $countryNameResolver,
        private readonly WhatsAppService $whatsAppService,
        private readonly WhatsAppMessageFormatter $whatsAppMessageFormatter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<Prediction> $predictions
     * @param list<User> $recipients
     *
     * @return array{emailsSent: int, whatsAppSent: bool}
     */
    public function sendFixturePredictionsSummary(Fixture $fixture, array $predictions, array $recipients): array
    {
        if ($recipients === []) {
            return ['emailsSent' => 0, 'whatsAppSent' => false];
        }

        $summaryRows = $this->buildSummaryRows($predictions);
        [$homeTeamName, $awayTeamName] = $this->resolveFixtureTeamNames($fixture);

        $subject = sprintf('Predicciones publicadas: %s vs %s', $homeTeamName, $awayTeamName);
        $sentCount = 0;
        $whatsAppSent = false;

        try {
            foreach ($recipients as $recipient) {
                $email = (new TemplatedEmail())
                    ->from($this->mailerFromAddress)
                    ->to(new Address($recipient->getEmail(), $recipient->getName()))
                    ->subject($subject)
                    ->htmlTemplate('emails/fixture_predictions_summary.html.twig')
                    ->context([
                        'recipient' => $recipient,
                        'fixture' => $fixture,
                        'homeTeamName' => $homeTeamName,
                        'awayTeamName' => $awayTeamName,
                        'summaryRows' => $summaryRows,
                    ]);

                $this->mailer->send($email);
                ++$sentCount;
            }
        } finally {
            if ($sentCount > 0) {
                $whatsAppSent = $this->sendFixturePredictionsWhatsApp($fixture, $predictions);
                if (!$whatsAppSent) {
                    $this->logger->warning('Fixture prediction emails sent but WhatsApp notification failed.', [
                        'fixtureId' => $fixture->getId(),
                        'emailsSent' => $sentCount,
                    ]);
                }
            }
        }

        return [
            'emailsSent' => $sentCount,
            'whatsAppSent' => $whatsAppSent,
        ];
    }

    /**
     * @param list<Prediction> $predictions
     */
    public function sendFixturePredictionsWhatsApp(Fixture $fixture, array $predictions, string $prefix = ''): bool
    {
        $message = $this->buildFixturePredictionsWhatsAppMessage($fixture, $predictions, $prefix);
        if ($message === null) {
            return false;
        }

        return $this->whatsAppService->sendMessage($message);
    }

    /**
     * @param list<Prediction> $predictions
     */
    public function buildFixturePredictionsWhatsAppMessage(Fixture $fixture, array $predictions, string $prefix = ''): ?string
    {
        $summaryRows = $this->buildSummaryRows($predictions);
        if ($summaryRows === []) {
            return null;
        }

        [$homeTeamName, $awayTeamName] = $this->resolveFixtureTeamNames($fixture);

        return $this->whatsAppMessageFormatter->formatFixturePredictionsClosed(
            $homeTeamName,
            $awayTeamName,
            $fixture->getKickoffAt(),
            $summaryRows,
            $prefix,
        );
    }

    /**
     * @param list<Prediction> $predictions
     *
     * @return list<array{name: string, homeScore: int, awayScore: int}>
     */
    private function buildSummaryRows(array $predictions): array
    {
        $summaryRows = [];
        foreach ($predictions as $prediction) {
            $user = $prediction->getUser();
            if (!$user instanceof User || !$user->isActive()) {
                continue;
            }

            $summaryRows[] = [
                'name' => $user->getName(),
                'homeScore' => $prediction->getPredictedHomeScore(),
                'awayScore' => $prediction->getPredictedAwayScore(),
            ];
        }

        return $summaryRows;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveFixtureTeamNames(Fixture $fixture): array
    {
        return [
            $this->countryNameResolver->resolveSpanishName(
                $fixture->getHomeTeam()?->getCode(),
                $fixture->getHomeTeam()?->getName()
            ),
            $this->countryNameResolver->resolveSpanishName(
                $fixture->getAwayTeam()?->getCode(),
                $fixture->getAwayTeam()?->getName()
            ),
        ];
    }
}