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

    #[ORM\Column(type: 'json')]
    #[Assert\All([
        new Assert\Regex(
            pattern: '/^(\d{1,3}\.){3}\d{1,3}(\/\d{1,2})?$|^[0-9a-fA-F:]+:+[0-9a-fA-F]*(\/\d{1,3})?$/',
            message: 'Each entry must be a valid IPv4 address, CIDR range (e.g. 10.0.0.0/8), or IPv6 address/range.',
        ),
    ])]
    private array $allowedCidrs = [];

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

    public function getAllowedCidrs(): array { return $this->allowedCidrs; }
    public function setAllowedCidrs(array $cidrs): static { $this->allowedCidrs = $cidrs; return $this; }

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

    public function isAllowedFromIp(string $ip): bool
    {
        if (empty($this->allowedCidrs)) {
            return true;
        }

        foreach ($this->allowedCidrs as $cidr) {
            if ($this->ipMatchesCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function ipMatchesCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $prefixLen] = explode('/', $cidr, 2);
        $bits = (int) $prefixLen;

        if (str_contains($ip, ':')) {
            $ipBin     = inet_pton($ip);
            $subnetBin = inet_pton($subnet);
            if ($ipBin === false || $subnetBin === false) {
                return false;
            }
            $fullBytes  = intdiv($bits, 8);
            $remainder  = $bits % 8;
            $mask       = str_repeat("\xff", $fullBytes)
                        . ($remainder ? chr(0xff << (8 - $remainder)) : '')
                        . str_repeat("\x00", 16 - $fullBytes - ($remainder ? 1 : 0));
            return ($ipBin & $mask) === ($subnetBin & $mask);
        }

        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $mask = $bits === 0 ? 0 : (int) (~0 << (32 - $bits));
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
