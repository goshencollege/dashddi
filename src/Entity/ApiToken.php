<?php

namespace App\Entity;

use App\Repository\ApiTokenRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
#[ORM\Table(name: 'api_token')]
class ApiToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash = '';

    #[ORM\Column(length: 255)]
    private string $ownerIdentifier = '';

    #[ORM\Column(type: 'json')]
    #[Assert\Count(min: 1, minMessage: 'Select at least one endpoint.')]
    private array $allowedRoutes = [];

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getTokenHash(): string { return $this->tokenHash; }
    public function setTokenHash(string $hash): static { $this->tokenHash = $hash; return $this; }

    public function getOwnerIdentifier(): string { return $this->ownerIdentifier; }
    public function setOwnerIdentifier(string $id): static { $this->ownerIdentifier = $id; return $this; }

    public function getAllowedRoutes(): array { return $this->allowedRoutes; }
    public function setAllowedRoutes(array $routes): static { $this->allowedRoutes = $routes; return $this; }

    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(?\DateTimeImmutable $dt): static { $this->expiresAt = $dt; return $this; }

    public function getLastUsedAt(): ?\DateTimeImmutable { return $this->lastUsedAt; }
    public function setLastUsedAt(?\DateTimeImmutable $dt): static { $this->lastUsedAt = $dt; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < new \DateTimeImmutable();
    }

    public function isAllowedOnRoute(string $route): bool
    {
        return in_array($route, $this->allowedRoutes, true);
    }
}
