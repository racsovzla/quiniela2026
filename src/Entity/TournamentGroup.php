<?php

namespace App\Entity;

use App\Repository\TournamentGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TournamentGroupRepository::class)]
#[UniqueEntity(fields: ['code'], message: 'Group code already exists.')]
class TournamentGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 2, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[A-Z]{1,2}$/', message: 'Use A-L as group code.')]
    private string $code;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $name;

    /**
     * @var Collection<int, Team>
     */
    #[ORM\OneToMany(targetEntity: Team::class, mappedBy: 'group')]
    private Collection $teams;

    /**
     * @var Collection<int, Fixture>
     */
    #[ORM\OneToMany(targetEntity: Fixture::class, mappedBy: 'group')]
    private Collection $fixtures;

    public function __construct()
    {
        $this->teams = new ArrayCollection();
        $this->fixtures = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    /**
     * @return Collection<int, Team>
     */
    public function getTeams(): Collection
    {
        return $this->teams;
    }

    /**
     * @return Collection<int, Fixture>
     */
    public function getFixtures(): Collection
    {
        return $this->fixtures;
    }
}
