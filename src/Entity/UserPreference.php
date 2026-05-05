<?php

namespace App\Entity;

use App\Repository\UserPreferenceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserPreferenceRepository::class)]
#[ORM\Table(name: 'user_preference')]
class UserPreference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $userIdentifier;

    #[ORM\Column(length: 16)]
    private string $theme = 'light';

    #[ORM\Column(length: 16)]
    private string $hostViewMode = 'host';

    #[ORM\Column(length: 16)]
    private string $subnetViewMode = 'name';

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $subnetSearch = null;

    public function __construct(string $userIdentifier)
    {
        $this->userIdentifier = $userIdentifier;
    }

    public function getId(): ?int { return $this->id; }

    public function getUserIdentifier(): string { return $this->userIdentifier; }

    public function getTheme(): string { return $this->theme; }
    public function setTheme(string $theme): static { $this->theme = $theme; return $this; }

    public function getHostViewMode(): string { return $this->hostViewMode; }
    public function setHostViewMode(string $mode): static { $this->hostViewMode = $mode; return $this; }

    public function getSubnetViewMode(): string { return $this->subnetViewMode; }
    public function setSubnetViewMode(string $mode): static { $this->subnetViewMode = $mode; return $this; }

    public function getSubnetSearch(): ?array { return $this->subnetSearch; }
    public function setSubnetSearch(?array $search): static { $this->subnetSearch = $search; return $this; }
}
