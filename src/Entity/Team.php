<?php

namespace App\Entity;

use App\Repository\TeamRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TeamRepository::class)]
#[UniqueEntity(fields: ['name'], message: 'Team already exists.')]
#[UniqueEntity(fields: ['code'], message: 'Team code already exists.')]
class Team
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 120)]
    private string $name;

    #[ORM\Column(length: 3, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[A-Z]{3}$/', message: 'Use FIFA style 3-letter code.')]
    private string $code;

    #[ORM\ManyToOne(inversedBy: 'teams')]
    #[ORM\JoinColumn(nullable: true)]
    private ?TournamentGroup $group = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = strtoupper(trim($code));

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
}
