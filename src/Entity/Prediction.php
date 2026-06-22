<?php

namespace App\Entity;

use App\Repository\PredictionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PredictionRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_user_fixture', columns: ['user_id', 'fixture_id'])]
#[UniqueEntity(fields: ['user', 'fixture'], message: 'Prediction already exists for this match.')]
class Prediction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'predictions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne(inversedBy: 'predictions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Fixture $fixture = null;

    #[ORM\Column]
    #[Assert\NotNull]
    #[Assert\GreaterThanOrEqual(0)]
    private int $predictedHomeScore;

    #[ORM\Column]
    #[Assert\NotNull]
    #[Assert\GreaterThanOrEqual(0)]
    private int $predictedAwayScore;

    #[ORM\Column(nullable: true)]
    #[Assert\GreaterThanOrEqual(0)]
    private ?int $predictedPenaltyHomeScore = null;

    #[ORM\Column(nullable: true)]
    #[Assert\GreaterThanOrEqual(0)]
    private ?int $predictedPenaltyAwayScore = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getFixture(): ?Fixture
    {
        return $this->fixture;
    }

    public function setFixture(?Fixture $fixture): static
    {
        $this->fixture = $fixture;

        return $this;
    }

    public function getPredictedHomeScore(): int
    {
        return $this->predictedHomeScore;
    }

    public function setPredictedHomeScore(int $predictedHomeScore): static
    {
        $this->predictedHomeScore = $predictedHomeScore;

        return $this;
    }

    public function getPredictedAwayScore(): int
    {
        return $this->predictedAwayScore;
    }

    public function setPredictedAwayScore(int $predictedAwayScore): static
    {
        $this->predictedAwayScore = $predictedAwayScore;

        return $this;
    }

    public function getPredictedPenaltyHomeScore(): ?int
    {
        return $this->predictedPenaltyHomeScore;
    }

    public function setPredictedPenaltyHomeScore(?int $predictedPenaltyHomeScore): static
    {
        $this->predictedPenaltyHomeScore = $predictedPenaltyHomeScore;

        return $this;
    }

    public function getPredictedPenaltyAwayScore(): ?int
    {
        return $this->predictedPenaltyAwayScore;
    }

    public function setPredictedPenaltyAwayScore(?int $predictedPenaltyAwayScore): static
    {
        $this->predictedPenaltyAwayScore = $predictedPenaltyAwayScore;

        return $this;
    }

    public function hasPenaltyPrediction(): bool
    {
        return $this->predictedPenaltyHomeScore !== null && $this->predictedPenaltyAwayScore !== null;
    }

    public function isCompleteForFixture(): bool
    {
        $fixture = $this->getFixture();
        if ($fixture?->isKnockout() && $this->predictedHomeScore === $this->predictedAwayScore) {
            return $this->hasPenaltyPrediction()
                && $this->predictedPenaltyHomeScore !== $this->predictedPenaltyAwayScore;
        }

        return true;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
