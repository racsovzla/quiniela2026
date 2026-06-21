<?php

namespace App\Service;

use App\Entity\Fixture;
use App\Repository\FixtureRepository;
use App\Repository\PredictionRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

class SendDueFixturePredictionEmailsService
{
    public const CATCH_UP_HOURS = 24;

    public function __construct(
        private readonly FixtureRepository $fixtureRepository,
        private readonly PredictionRepository $predictionRepository,
        private readonly UserRepository $userRepository,
        private readonly FixturePredictionEmailService $fixturePredictionEmailService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{processed: int, sent: int, skipped: int, recipientCount: int, whatsAppFailed: int}
     */
    public function dispatchDueEmails(?\DateTimeImmutable $nowUtc = null, bool $dryRun = false): array
    {
        $nowUtc ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $catchUpSinceUtc = $nowUtc->modify(sprintf('-%d hours', self::CATCH_UP_HOURS));

        $fixtures = $this->fixtureRepository->findDueForPredictionEmail($nowUtc, $catchUpSinceUtc);

        $stats = [
            'processed' => 0,
            'sent' => 0,
            'skipped' => 0,
            'recipientCount' => 0,
            'whatsAppFailed' => 0,
        ];

        foreach ($fixtures as $fixture) {
            ++$stats['processed'];

            $predictions = $this->predictionRepository->findByFixtureWithUser($fixture);
            if ($predictions === []) {
                ++$stats['skipped'];
                if (!$dryRun) {
                    $this->markAsSent($fixture, $nowUtc);
                }
                continue;
            }

            $recipients = $this->userRepository->findApprovedVerifiedRecipients();
            if ($recipients === []) {
                ++$stats['skipped'];
                if (!$dryRun) {
                    $this->markAsSent($fixture, $nowUtc);
                }
                continue;
            }

            if (!$dryRun) {
                $dispatchResult = $this->fixturePredictionEmailService->sendFixturePredictionsSummary(
                    $fixture,
                    $predictions,
                    $recipients,
                );
                $this->markAsSent($fixture, $nowUtc);
                $stats['recipientCount'] += $dispatchResult['emailsSent'];
                if (!$dispatchResult['whatsAppSent']) {
                    ++$stats['whatsAppFailed'];
                }
            }

            ++$stats['sent'];
        }

        if (!$dryRun && $stats['processed'] > 0) {
            $this->entityManager->flush();
        }

        return $stats;
    }

    private function markAsSent(Fixture $fixture, \DateTimeImmutable $sentAtUtc): void
    {
        $fixture->setPredictionsEmailSentAt($sentAtUtc);
        $this->entityManager->persist($fixture);
    }
}
