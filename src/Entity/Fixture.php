<?php

namespace App\Entity;

use App\Repository\FixtureRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: FixtureRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Fixture
{
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_POSTPONED = 'postponed';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_RESCHEDULED = 'rescheduled';

    public const STAGE_GROUP = 'group';
    public const STAGE_R32 = 'r32';
    public const STAGE_R16 = 'r16';
    public const STAGE_QF = 'qf';
    public const STAGE_SF = 'sf';
    public const STAGE_FINAL = 'final';
    public const STAGE_THIRD = 'third';

    /** @var list<string> */
    public const STAGES = [
        self::STAGE_GROUP,
        self::STAGE_R32,
        self::STAGE_R16,
        self::STAGE_QF,
        self::STAGE_SF,
        self::STAGE_FINAL,
        self::STAGE_THIRD,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Team $homeTeam = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Team $awayTeam = null;

    #[ORM\ManyToOne(inversedBy: 'fixtures')]
    #[ORM\JoinColumn(nullable: true)]
    private ?TournamentGroup $group = null;

    #[ORM\Column]
    private \DateTimeImmutable $kickoffAt;

    #[ORM\Column(nullable: true)]
    #[Assert\GreaterThanOrEqual(0)]
    private ?int $homeScore = null;

    #[ORM\Column(nullable: true)]
    #[Assert\GreaterThanOrEqual(0)]
    private ?int $awayScore = null;

    #[ORM\Column(length: 10, options: ['default' => 'group'])]
    private string $stage = self::STAGE_GROUP;

    #[ORM\Column(nullable: true)]
    #[Assert\GreaterThanOrEqual(0)]
    private ?int $penaltyHomeScore = null;

    #[ORM\Column(nullable: true)]
    #[Assert\GreaterThanOrEqual(0)]
    private ?int $penaltyAwayScore = null;

    #[ORM\Column(length: 20, nullable: true, unique: true)]
    private ?string $fifaMatchId = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_SCHEDULED;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $predictionsEmailSentAt = null;

    /**
     * @var Collection<int, Prediction>
     */
    #[ORM\OneToMany(targetEntity: Prediction::class, mappedBy: 'fixture', orphanRemoval: true)]
    private Collection $predictions;

    public function __construct()
    {
        $this->predictions = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onCreate(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHomeTeam(): ?Team
    {
        return $this->homeTeam;
    }

    public function setHomeTeam(?Team $homeTeam): static
    {
        $this->homeTeam = $homeTeam;

        return $this;
    }

    public function getAwayTeam(): ?Team
    {
        return $this->awayTeam;
    }

    public function setAwayTeam(?Team $awayTeam): static
    {
        $this->awayTeam = $awayTeam;

        return $this;
    }

    public function getGroup(): ?TournamentGroup
    {
        return $this->group;
    }

    public function setGroup(?TournamentGroup $group): static
    {
        $this->group = $group;

        return $this;
    }

    public function getKickoffAt(): \DateTimeImmutable
    {
        return $this->kickoffAt;
    }

    public function setKickoffAt(\DateTimeImmutable $kickoffAt): static
    {
        $this->kickoffAt = $kickoffAt;

        return $this;
    }

    public function getHomeScore(): ?int
    {
        return $this->homeScore;
    }

    public function setHomeScore(?int $homeScore): static
    {
        $this->homeScore = $homeScore;

        return $this;
    }

    public function getAwayScore(): ?int
    {
        return $this->awayScore;
    }

    public function setAwayScore(?int $awayScore): static
    {
        $this->awayScore = $awayScore;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function hasFinalScore(): bool
    {
        return $this->homeScore !== null && $this->awayScore !== null;
    }

    public function getStage(): string
    {
        return $this->stage;
    }

    public function setStage(string $stage): static
    {
        $this->stage = $stage;

        return $this;
    }

    public function isKnockout(): bool
    {
        return $this->stage !== self::STAGE_GROUP;
    }

    public function getPenaltyHomeScore(): ?int
    {
        return $this->penaltyHomeScore;
    }

    public function setPenaltyHomeScore(?int $penaltyHomeScore): static
    {
        $this->penaltyHomeScore = $penaltyHomeScore;

        return $this;
    }

    public function getPenaltyAwayScore(): ?int
    {
        return $this->penaltyAwayScore;
    }

    public function setPenaltyAwayScore(?int $penaltyAwayScore): static
    {
        $this->penaltyAwayScore = $penaltyAwayScore;

        return $this;
    }

    public function wentToPenalties(): bool
    {
        return $this->penaltyHomeScore !== null && $this->penaltyAwayScore !== null;
    }

    public function hasFinalPenaltyScore(): bool
    {
        return $this->wentToPenalties();
    }

    public function getFifaMatchId(): ?string
    {
        return $this->fifaMatchId;
    }

    public function setFifaMatchId(?string $fifaMatchId): static
    {
        $this->fifaMatchId = $fifaMatchId;

        return $this;
    }

    public static function stageGroupName(string $stage): string
    {
        return match ($stage) {
            self::STAGE_R32 => 'Dieciseisavos',
            self::STAGE_R16 => 'Octavos',
            self::STAGE_QF => 'Cuartos de final',
            self::STAGE_SF => 'Semifinal',
            self::STAGE_FINAL => 'Final',
            self::STAGE_THIRD => 'Tercer puesto',
            default => $stage,
        };
    }

    /**
     * @return list<string>
     */
    public static function knockoutStages(): array
    {
        return [
            self::STAGE_R32,
            self::STAGE_R16,
            self::STAGE_QF,
            self::STAGE_SF,
            self::STAGE_FINAL,
            self::STAGE_THIRD,
        ];
    }

    public function getStageLabel(): string
    {
        if ($this->stage === self::STAGE_GROUP) {
            return 'Grupos';
        }

        return self::stageGroupName($this->stage);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getPredictionsEmailSentAt(): ?\DateTimeImmutable
    {
        return $this->predictionsEmailSentAt;
    }

    public function setPredictionsEmailSentAt(?\DateTimeImmutable $predictionsEmailSentAt): static
    {
        $this->predictionsEmailSentAt = $predictionsEmailSentAt;

        return $this;
    }

    public function hasPredictionsEmailBeenSent(): bool
    {
        return $this->predictionsEmailSentAt !== null;
    }

    /**
     * @return list<string>
     */
    public static function allStatuses(): array
    {
        return [
            self::STATUS_SCHEDULED,
            self::STATUS_FINISHED,
            self::STATUS_POSTPONED,
            self::STATUS_SUSPENDED,
            self::STATUS_RESCHEDULED,
        ];
    }

    /**
     * Statuses that still count as an upcoming/active match (not finished).
     *
     * @return list<string>
     */
    public static function activeStatuses(): array
    {
        return [
            self::STATUS_SCHEDULED,
            self::STATUS_POSTPONED,
            self::STATUS_SUSPENDED,
            self::STATUS_RESCHEDULED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function potentiallyLiveStatuses(): array
    {
        return [
            self::STATUS_SCHEDULED,
            self::STATUS_RESCHEDULED,
        ];
    }

    public function isDelayed(): bool
    {
        return $this->status === self::STATUS_POSTPONED
            || $this->status === self::STATUS_SUSPENDED;
    }

    public function isPotentiallyLive(): bool
    {
        return in_array($this->status, self::potentiallyLiveStatuses(), true);
    }

    public function isEditableAt(\DateTimeImmutable $nowUtc): bool
    {
        if ($this->status === self::STATUS_FINISHED) {
            return false;
        }

        if ($this->isDelayed()) {
            return true;
        }

        return $nowUtc < $this->getKickoffAt()->modify('-5 minutes');
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_FINISHED => 'Finalizado',
            self::STATUS_POSTPONED => 'Retrasado',
            self::STATUS_SUSPENDED => 'Suspendido',
            self::STATUS_RESCHEDULED => 'Reprogramado',
            default => 'Programado',
        };
    }

    public function getStatusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_FINISHED => 'text-bg-secondary',
            self::STATUS_POSTPONED => 'text-bg-warning',
            self::STATUS_SUSPENDED => 'text-bg-dark',
            self::STATUS_RESCHEDULED => 'text-bg-info',
            default => 'text-bg-primary',
        };
    }

    public function clearPartialScores(): static
    {
        $this->homeScore = null;
        $this->awayScore = null;
        $this->penaltyHomeScore = null;
        $this->penaltyAwayScore = null;

        return $this;
    }

    public function applyRescheduleReset(\DateTimeImmutable $newKickoffAt): static
    {
        $this->kickoffAt = $newKickoffAt;
        $this->status = self::STATUS_RESCHEDULED;
        $this->predictionsEmailSentAt = null;
        $this->clearPartialScores();

        return $this;
    }
}
