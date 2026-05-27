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

        $addresses = [];
        foreach ($recipients as $recipient) {
            $addresses[] = new Address($recipient->getEmail(), $recipient->getName());
        }

        $email = (new TemplatedEmail())
            ->from($this->mailerFromAddress)
            ->to(...$addresses)
            ->subject(sprintf('Predicciones publicadas: %s vs %s', $homeTeamName, $awayTeamName))
            ->htmlTemplate('emails/fixture_predictions_summary.html.twig')
            ->context([
                'recipient' => $recipients[0],
                'fixture' => $fixture,
                'homeTeamName' => $homeTeamName,
                'awayTeamName' => $awayTeamName,
                'summaryRows' => $summaryRows,
            ]);

        $this->mailer->send($email);

        return count($addresses);
    }
}