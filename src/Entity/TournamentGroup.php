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

    #[ORM\Column(length: 10, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(
        pattern: '/^(?:[A-L]|r32|r16|qf|sf|final|third)$/i',
        message: 'Use A-L for groups or a knockout phase code (r32, r16, qf, sf, final, third).',
    )]
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
        $code = trim($code);
        $this->code = preg_match('/^[A-L]$/i', $code) === 1
            ? strtoupper($code)
            : strtolower($code);

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
