<?php

namespace App\Service;

use App\Entity\Fixture;
use App\Entity\TournamentGroup;
use App\Repository\FixtureRepository;
use App\Repository\TeamRepository;
use App\Repository\TournamentGroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class FifaFixtureDiscoveryService
{
    public function __construct(
        private readonly FifaCalendarClient $fifaCalendarClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly FixtureRepository $fixtureRepository,
        private readonly TeamRepository $teamRepository,
        private readonly TournamentGroupRepository $groupRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Fetches FIFA feed and imports fixtures missing from the database.
     *
     * @return array{
     *     created: int,
     *     updated: int,
     *     skipped: int,
     *     createdFixtures: list<Fixture>
     * }
     */
    public function importNewFixtures(bool $dryRun = false, bool $knockoutOnly = false): array
    {
        try {
            $rows = $this->fifaCalendarClient->fetchAllMatches();
        } catch (\RuntimeException $exception) {
            $this->logger->error('FifaFixtureDiscovery: could not fetch FIFA API.', [
                'error' => $exception->getMessage(),
            ]);

            return [
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'createdFixtures' => [],
            ];
        }

        return $this->syncFromRows($rows, $dryRun, $knockoutOnly);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array{
     *     created: int,
     *     updated: int,
     *     skipped: int,
     *     createdFixtures: list<Fixture>
     * }
     */
    public function syncFromRows(array $rows, bool $dryRun = false, bool $knockoutOnly = false): array
    {
        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'createdFixtures' => [],
        ];

        foreach ($rows as $row) {
            if ($knockoutOnly && $this->fifaCalendarClient->extractStageKey($row) === Fixture::STAGE_GROUP) {
                ++$stats['skipped'];
                continue;
            }

            $result = $this->processRow($row, $dryRun);
            if ($result === null) {
                ++$stats['skipped'];
                continue;
            }

            if ($result['created']) {
                ++$stats['created'];
                $stats['createdFixtures'][] = $result['fixture'];
                continue;
            }

            if ($result['updated']) {
                ++$stats['updated'];
            }
        }

        if (!$dryRun && ($stats['created'] > 0 || $stats['updated'] > 0)) {
            $this->entityManager->flush();
        }

        if ($dryRun) {
            $this->entityManager->clear();
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{fixture: Fixture, created: bool, updated: bool}|null
     */
    private function processRow(array $row, bool $dryRun): ?array
    {
        if (!$this->fifaCalendarClient->hasBothTeamsConfirmed($row)) {
            return null;
        }

        $stage = $this->fifaCalendarClient->extractStageKey($row);
        $homeCode = $this->fifaCalendarClient->teamCode($row, 'Home');
        $awayCode = $this->fifaCalendarClient->teamCode($row, 'Away');
        $kickoffUtc = $this->fifaCalendarClient->kickoffIso($row);
        $fifaMatchId = $this->fifaCalendarClient->extractMatchId($row);

        if ($homeCode === null || $awayCode === null || $kickoffUtc === null || $fifaMatchId === null) {
            return null;
        }

        $homeTeam = $this->teamRepository->findOneBy(['code' => $homeCode]);
        $awayTeam = $this->teamRepository->findOneBy(['code' => $awayCode]);

        if (!$homeTeam || !$awayTeam) {
            $this->logger->warning('FifaFixtureDiscovery: skipping match, team not in DB.', [
                'home' => $homeCode,
                'away' => $awayCode,
                'fifaMatchId' => $fifaMatchId,
            ]);

            return null;
        }

        try {
            $kickoffAt = new \DateTimeImmutable($kickoffUtc, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }

        $group = $this->resolveGroup($stage, $row, $dryRun);
        if ($group === false) {
            return null;
        }

        $fixture = $this->fixtureRepository->findOneByFifaMatchId($fifaMatchId);
        if (!$fixture instanceof Fixture) {
            $fixture = $this->fixtureRepository->findOneByTeamsAndStage($homeTeam, $awayTeam, $stage);
        }

        if (!$fixture instanceof Fixture) {
            $fixture = (new Fixture())
                ->setHomeTeam($homeTeam)
                ->setAwayTeam($awayTeam)
                ->setStage($stage)
                ->setFifaMatchId($fifaMatchId)
                ->setKickoffAt($kickoffAt)
                ->setStatus(Fixture::STATUS_SCHEDULED);

            if ($group instanceof TournamentGroup) {
                $fixture->setGroup($group);
            }

            if (!$dryRun) {
                $this->entityManager->persist($fixture);
            }

            return ['fixture' => $fixture, 'created' => true, 'updated' => false];
        }

        $changed = false;

        if ($fixture->getKickoffAt()?->format('Y-m-d H:i:s') !== $kickoffAt->format('Y-m-d H:i:s')) {
            $fixture->setKickoffAt($kickoffAt);
            $changed = true;
        }

        if ($fixture->getStage() !== $stage) {
            $fixture->setStage($stage);
            $changed = true;
        }

        if ($fixture->getFifaMatchId() !== $fifaMatchId) {
            $fixture->setFifaMatchId($fifaMatchId);
            $changed = true;
        }

        if ($group instanceof TournamentGroup && $fixture->getGroup()?->getId() !== $group->getId()) {
            $fixture->setGroup($group);
            $changed = true;
        }

        if (!$changed) {
            return null;
        }

        if (!$dryRun) {
            $this->entityManager->persist($fixture);
        }

        return ['fixture' => $fixture, 'created' => false, 'updated' => true];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return TournamentGroup|null|false null when no group applies, false on failure
     */
    private function resolveGroup(string $stage, array $row, bool $dryRun): TournamentGroup|null|false
    {
        if ($stage === Fixture::STAGE_GROUP) {
            $groupCode = $this->fifaCalendarClient->extractGroupCode($row);
            if ($groupCode === null) {
                return null;
            }

            return $this->groupRepository->findOneBy(['code' => $groupCode]);
        }

        return $this->ensurePhaseGroup($stage, $dryRun);
    }

    private function ensurePhaseGroup(string $stage, bool $dryRun): TournamentGroup|false
    {
        if (!in_array($stage, Fixture::knockoutStages(), true)) {
            return false;
        }

        $existing = $this->groupRepository->findOneBy(['code' => $stage]);
        if ($existing instanceof TournamentGroup) {
            return $existing;
        }

        $group = (new TournamentGroup())
            ->setCode($stage)
            ->setName(Fixture::stageGroupName($stage));

        if (!$dryRun) {
            $this->entityManager->persist($group);
            $this->entityManager->flush();
        }

        return $group;
    }
}
