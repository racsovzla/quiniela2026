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
        private readonly FifaFixtureDiscoveryService $fifaFixtureDiscoveryService,
    ) {
    }

    /**
     * @return array{
     *     checked: int,
     *     matched: int,
     *     updated: int,
     *     finished: int,
     *     skipped: int,
     *     schedulesCreated: int,
     *     schedulesUpdated: int,
     *     postponed: int,
     *     suspended: int
     * }
     */
    public function syncLiveScores(?\DateTimeImmutable $nowUtc = null, bool $dryRun = false): array
    {
        $nowUtc ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $discoveryStats = $this->fifaFixtureDiscoveryService->importNewFixtures($dryRun);

        $stats = [
            'checked' => 0,
            'matched' => 0,
            'updated' => 0,
            'finished' => 0,
            'skipped' => 0,
            'schedulesCreated' => $discoveryStats['created'],
            'schedulesUpdated' => $discoveryStats['updated'],
            'postponed' => 0,
            'suspended' => 0,
        ];

        try {
            $fifaRows = $this->fifaCalendarClient->fetchAllMatches();
        } catch (\RuntimeException) {
            return $stats;
        }

        $fifaByKey = $this->fifaCalendarClient->indexByTeamCodes($fifaRows);

        $overdueFixtures = $this->fixtureRepository->findOverdueForDelayCheck($nowUtc);
        $stats['checked'] = count($overdueFixtures);

        foreach ($overdueFixtures as $fixture) {
            $fifaRow = $this->matchFifaRow($fixture, $fifaByKey);
            if (!is_array($fifaRow)) {
                ++$stats['skipped'];
                continue;
            }

            if ($this->fifaCalendarClient->isFinished($fifaRow)) {
                continue;
            }

            if ($this->fifaCalendarClient->hasFutureKickoff($fifaRow, $nowUtc)) {
                continue;
            }

            $delayStatus = $this->resolveDelayStatus($fixture, $fifaRow);
            if ($delayStatus === null || $fixture->getStatus() === $delayStatus) {
                continue;
            }

            if (!$dryRun) {
                $fixture->setStatus($delayStatus);
                if ($delayStatus === Fixture::STATUS_POSTPONED) {
                    $fixture->clearPartialScores();
                }
                $this->entityManager->persist($fixture);
            }

            ++$stats['updated'];
            if ($delayStatus === Fixture::STATUS_POSTPONED) {
                ++$stats['postponed'];
            } else {
                ++$stats['suspended'];
            }
        }

        $liveFixtures = $this->fixtureRepository->findScheduledPotentiallyLive($nowUtc);

        foreach ($liveFixtures as $fixture) {
            $fifaRow = $this->matchFifaRow($fixture, $fifaByKey);
            if (!is_array($fifaRow)) {
                ++$stats['skipped'];
                continue;
            }

            ++$stats['matched'];

            if ($this->fifaCalendarClient->isPostponed($fifaRow)
                || $this->fifaCalendarClient->hasFutureKickoff($fifaRow, $nowUtc)
            ) {
                if (!$dryRun && $fixture->getStatus() !== Fixture::STATUS_POSTPONED) {
                    $fixture
                        ->setStatus(Fixture::STATUS_POSTPONED)
                        ->clearPartialScores();
                    $this->entityManager->persist($fixture);
                    ++$stats['updated'];
                    ++$stats['postponed'];
                }
                continue;
            }

            if ($this->fifaCalendarClient->isSuspendedInPlay($fifaRow)) {
                if (!$dryRun && $fixture->getStatus() !== Fixture::STATUS_SUSPENDED) {
                    $fixture->setStatus(Fixture::STATUS_SUSPENDED);
                    $this->entityManager->persist($fixture);
                    ++$stats['updated'];
                    ++$stats['suspended'];
                }
                continue;
            }

            $scores = $this->fifaCalendarClient->extractScores($fifaRow);
            if (null === $scores) {
                ++$stats['skipped'];
                continue;
            }

            $penaltyScores = $this->fifaCalendarClient->extractPenaltyScores($fifaRow);
            $shouldFinish = $this->fifaCalendarClient->isFinished($fifaRow);
            $newStatus = $shouldFinish
                ? Fixture::STATUS_FINISHED
                : ($fixture->getStatus() === Fixture::STATUS_RESCHEDULED
                    ? Fixture::STATUS_RESCHEDULED
                    : Fixture::STATUS_SCHEDULED);

            $hasScoreChange = $fixture->getHomeScore() !== $scores['home']
                || $fixture->getAwayScore() !== $scores['away'];
            $hasPenaltyChange = $penaltyScores !== null && (
                $fixture->getPenaltyHomeScore() !== $penaltyScores['home']
                || $fixture->getPenaltyAwayScore() !== $penaltyScores['away']
            );
            $hasStatusChange = $fixture->getStatus() !== $newStatus;

            if (!$hasScoreChange && !$hasPenaltyChange && !$hasStatusChange) {
                continue;
            }

            if (!$dryRun) {
                $fixture
                    ->setHomeScore($scores['home'])
                    ->setAwayScore($scores['away'])
                    ->setStatus($newStatus);

                if ($penaltyScores !== null) {
                    $fixture
                        ->setPenaltyHomeScore($penaltyScores['home'])
                        ->setPenaltyAwayScore($penaltyScores['away']);
                }

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
            $this->notifyNewFixtures($discoveryStats['createdFixtures']);
        }

        return $stats;
    }

    /**
     * @param array<string, array<string, mixed>> $fifaByKey
     *
     * @return array<string, mixed>|null
     */
    private function matchFifaRow(Fixture $fixture, array $fifaByKey): ?array
    {
        $homeCode = $fixture->getHomeTeam()?->getCode();
        $awayCode = $fixture->getAwayTeam()?->getCode();

        if (null === $homeCode || null === $awayCode) {
            return null;
        }

        $key = $this->fifaCalendarClient->matchKey($homeCode, $awayCode);

        return $fifaByKey[$key] ?? null;
    }

    /**
     * @param array<string, mixed> $fifaRow
     */
    private function resolveDelayStatus(Fixture $fixture, array $fifaRow): ?string
    {
        if ($this->fifaCalendarClient->isSuspendedInPlay($fifaRow)) {
            return Fixture::STATUS_SUSPENDED;
        }

        if ($this->fifaCalendarClient->isPostponed($fifaRow)) {
            return Fixture::STATUS_POSTPONED;
        }

        $scores = $this->fifaCalendarClient->extractScores($fifaRow);
        if ($scores !== null && ($scores['home'] > 0 || $scores['away'] > 0)) {
            return null;
        }

        if ($fixture->hasFinalScore() && ($fixture->getHomeScore() > 0 || $fixture->getAwayScore() > 0)) {
            return Fixture::STATUS_SUSPENDED;
        }

        return Fixture::STATUS_POSTPONED;
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

    /**
     * @param list<Fixture> $createdFixtures
     */
    private function notifyNewFixtures(array $createdFixtures): void
    {
        foreach ($createdFixtures as $fixture) {
            $homeTeam = $this->countryNameResolver->resolveSpanishName(
                $fixture->getHomeTeam()?->getCode(),
                $fixture->getHomeTeam()?->getName(),
            );
            $awayTeam = $this->countryNameResolver->resolveSpanishName(
                $fixture->getAwayTeam()?->getCode(),
                $fixture->getAwayTeam()?->getName(),
            );

            $phaseName = $fixture->getGroup()?->getName() ?? $fixture->getStageLabel();

            $message = $this->whatsAppMessageFormatter->formatNewFixtureAvailable(
                $phaseName,
                $homeTeam,
                $awayTeam,
                $fixture->getKickoffAt(),
            );

            $this->whatsAppService->sendMessage($message);
        }
    }
}
