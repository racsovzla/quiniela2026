<?php

namespace App\Service;

use App\Entity\Fixture;
use App\Repository\FixtureRepository;
use App\Repository\UserRepository;
use App\Repository\PredictionRepository;
use Doctrine\ORM\EntityManagerInterface;

class SyncLiveFixtureScoresService
{
    public function __construct(
        private readonly FixtureRepository $fixtureRepository,
        private readonly FifaCalendarClient $fifaCalendarClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly PredictionRepository $predictionRepository,
        private readonly CountryNameResolver $countryNameResolver,
        private readonly WhatsAppService $whatsAppService,
        private readonly WhatsAppMessageFormatter $whatsAppMessageFormatter,
    ) {
    }

    /**
     * @return array{checked: int, matched: int, updated: int, finished: int, skipped: int}
     */
    public function syncLiveScores(?\DateTimeImmutable $nowUtc = null, bool $dryRun = false): array
    {
        $nowUtc ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $fixtures = $this->fixtureRepository->findScheduledPotentiallyLive($nowUtc);

        $stats = [
            'checked' => count($fixtures),
            'matched' => 0,
            'updated' => 0,
            'finished' => 0,
            'skipped' => 0,
        ];

        if ($fixtures === []) {
            return $stats;
        }

        $fifaRows = $this->fifaCalendarClient->fetchGroupStageMatches();
        $fifaByKey = $this->fifaCalendarClient->indexByTeamCodes($fifaRows);

        foreach ($fixtures as $fixture) {
            $homeCode = $fixture->getHomeTeam()?->getCode();
            $awayCode = $fixture->getAwayTeam()?->getCode();

            if (null === $homeCode || null === $awayCode) {
                ++$stats['skipped'];
                continue;
            }

            $key = $this->fifaCalendarClient->matchKey($homeCode, $awayCode);
            $fifaRow = $fifaByKey[$key] ?? null;

            if (!is_array($fifaRow)) {
                ++$stats['skipped'];
                continue;
            }

            ++$stats['matched'];

            $scores = $this->fifaCalendarClient->extractScores($fifaRow);
            if (null === $scores) {
                ++$stats['skipped'];
                continue;
            }

            $shouldFinish = $this->fifaCalendarClient->isFinished($fifaRow);
            $newStatus = $shouldFinish ? Fixture::STATUS_FINISHED : Fixture::STATUS_SCHEDULED;

            $hasScoreChange = $fixture->getHomeScore() !== $scores['home']
                || $fixture->getAwayScore() !== $scores['away'];
            $hasStatusChange = $fixture->getStatus() !== $newStatus;

            if (!$hasScoreChange && !$hasStatusChange) {
                continue;
            }

            if (!$dryRun) {
                $fixture
                    ->setHomeScore($scores['home'])
                    ->setAwayScore($scores['away'])
                    ->setStatus($newStatus);
                $this->entityManager->persist($fixture);
            }

            ++$stats['updated'];
            if ($shouldFinish) {
                ++$stats['finished'];
            }
        }

        if (!$dryRun && $stats['updated'] > 0) {
            $this->entityManager->flush();
        }

        if (!$dryRun && $stats['finished'] > 0) {
            $this->checkAndNotifyMissingPredictionsForNextFixture($nowUtc);
        }

        return $stats;
    }

    private function checkAndNotifyMissingPredictionsForNextFixture(\DateTimeImmutable $nowUtc): void
    {
        $nextFixture = $this->fixtureRepository->findNextScheduledFixture();
        if (null === $nextFixture) {
            return;
        }

        $users = $this->userRepository->findApprovedVerifiedRecipients();
        if ($users === []) {
            return;
        }

        $missingUsers = [];
        foreach ($users as $user) {
            $prediction = $this->predictionRepository->findOneByUserAndFixture($user, $nextFixture);
            if (null === $prediction) {
                $missingUsers[] = $user->getName();
            }
        }

        if ($missingUsers !== []) {
            $homeTeam = $this->countryNameResolver->resolveSpanishName(
                $nextFixture->getHomeTeam()?->getCode(),
                $nextFixture->getHomeTeam()?->getName()
            );
            $awayTeam = $this->countryNameResolver->resolveSpanishName(
                $nextFixture->getAwayTeam()?->getCode(),
                $nextFixture->getAwayTeam()?->getName()
            );

            $message = $this->whatsAppMessageFormatter->formatMissingPredictionsReminder(
                $homeTeam,
                $awayTeam,
                $missingUsers,
            );

            $this->whatsAppService->sendMessage($message);
        }
    }
}
