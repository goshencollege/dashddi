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
    private string $theme = 'purple';

    #[ORM\Column(length: 16)]
    private string $hostViewMode = 'host';

    #[ORM\Column(length: 16)]
    private string $subnetViewMode = 'name';

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $subnetSearch = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $hostSearch = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $hostCollapsedSections = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $hostSearchHistory = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $dhcpLeaseSearch = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $clearpassAuthLogSearch = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $domainRecordSearch = null;

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

    public function getHostSearch(): ?array { return $this->hostSearch; }
    public function setHostSearch(?array $search): static { $this->hostSearch = $search; return $this; }

    public function getHostCollapsedSections(): ?array { return $this->hostCollapsedSections; }
    public function setHostCollapsedSections(?array $sections): static { $this->hostCollapsedSections = $sections; return $this; }

    public function getHostSearchHistory(): ?array { return $this->hostSearchHistory; }
    public function setHostSearchHistory(?array $history): static { $this->hostSearchHistory = $history; return $this; }

    public function getDhcpLeaseSearch(): ?array { return $this->dhcpLeaseSearch; }
    public function setDhcpLeaseSearch(?array $search): static { $this->dhcpLeaseSearch = $search; return $this; }

    public function getClearpassAuthLogSearch(): ?array { return $this->clearpassAuthLogSearch; }
    public function setClearpassAuthLogSearch(?array $search): static { $this->clearpassAuthLogSearch = $search; return $this; }

    public function getDomainRecordSearch(): ?array { return $this->domainRecordSearch; }
    public function setDomainRecordSearch(?array $search): static { $this->domainRecordSearch = $search; return $this; }
}
