<?php

namespace App\Entity;

use App\Repository\DnsViewRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DnsViewRepository::class)]
#[ORM\Table(name: 'dns_view')]
#[UniqueEntity(fields: ['name'], message: 'This view name is already in use.')]
class DnsView
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    #[Assert\Regex(
        pattern: '/^[A-Za-z0-9_\-.]+$/',
        message: 'View name may only contain letters, digits, hyphens, underscores, and dots.'
    )]
    private string $name = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $matchClients = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $allowQuery = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $allowTransfer = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $alsoNotify = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $extraOptions = null;

    #[ORM\Column(length: 45, nullable: true)]
    #[Assert\Ip(version: 'all')]
    private ?string $nsUpdateSourceAddress = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isPublic = false;

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getMatchClients(): array { return $this->matchClients ?? []; }
    public function setMatchClients(array $v): static { $this->matchClients = $v ?: null; return $this; }

    public function getAllowQuery(): array { return $this->allowQuery ?? []; }
    public function setAllowQuery(array $v): static { $this->allowQuery = $v ?: null; return $this; }

    public function getAllowTransfer(): array { return $this->allowTransfer ?? []; }
    public function setAllowTransfer(array $v): static { $this->allowTransfer = $v ?: null; return $this; }

    public function getAlsoNotify(): array { return $this->alsoNotify ?? []; }
    public function setAlsoNotify(array $v): static { $this->alsoNotify = $v ?: null; return $this; }

    public function getExtraOptions(): ?string { return $this->extraOptions; }
    public function setExtraOptions(?string $extraOptions): static { $this->extraOptions = $extraOptions; return $this; }

    public function getNsUpdateSourceAddress(): ?string
    {
        // Compute a stable loopback address from the view ID for nsupdate source routing.
        // 127.0.0.1 is reserved for the server directive; views start at 127.0.0.2.
        return ($this->id !== null && $this->id < 254) ? ('127.0.0.' . ($this->id + 1)) : null;
    }

    public function isPublic(): bool { return $this->isPublic; }
    public function setIsPublic(bool $isPublic): static { $this->isPublic = $isPublic; return $this; }

    public function __toString(): string { return $this->name; }
}
