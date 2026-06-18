<?php

namespace App\Service;

use App\Entity\Fixture;
use App\Entity\Prediction;
use App\Entity\User;
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
    ) {
    }

    /**
     * @param list<Prediction> $predictions
     * @param list<User> $recipients
     */
    public function sendFixturePredictionsSummary(Fixture $fixture, array $predictions, array $recipients): int
    {
        if ($recipients === []) {
            return 0;
        }

        $summaryRows = [];
        foreach ($predictions as $prediction) {
            $user = $prediction->getUser();
            if (!$user instanceof User) {
                continue;
            }

            $summaryRows[] = [
                'name' => $user->getName(),
                'homeScore' => $prediction->getPredictedHomeScore(),
                'awayScore' => $prediction->getPredictedAwayScore(),
            ];
        }

        $homeTeamName = $this->countryNameResolver->resolveSpanishName(
            $fixture->getHomeTeam()?->getCode(),
            $fixture->getHomeTeam()?->getName()
        );
        $awayTeamName = $this->countryNameResolver->resolveSpanishName(
            $fixture->getAwayTeam()?->getCode(),
            $fixture->getAwayTeam()?->getName()
        );

        $subject = sprintf('Predicciones publicadas: %s vs %s', $homeTeamName, $awayTeamName);
        $sentCount = 0;

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

        $whatsappMessage = sprintf("Pronósticos para el partido %s vs %s:\n", $homeTeamName, $awayTeamName);
        foreach ($summaryRows as $row) {
            $whatsappMessage .= sprintf(
                "- %s: %d - %d\n",
                $row['name'],
                $row['homeScore'],
                $row['awayScore']
            );
        }
        $this->whatsAppService->sendMessage(rtrim($whatsappMessage));

        return $sentCount;
    }
}