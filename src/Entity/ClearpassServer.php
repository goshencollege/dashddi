<?php

namespace App\Entity;

use App\Entity\Trait\AuditableTrait;
use App\Repository\ClearpassServerRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ClearpassServerRepository::class)]
#[ORM\Table(name: 'clearpass_server')]
class ClearpassServer
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $name = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Url]
    private string $apiUrl = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $clientId = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $clientSecret = '';

    #[ORM\Column]
    private bool $verifyTls = true;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getApiUrl(): string { return $this->apiUrl; }
    public function setApiUrl(string $apiUrl): static { $this->apiUrl = rtrim($apiUrl, '/'); return $this; }

    public function getClientId(): string { return $this->clientId; }
    public function setClientId(string $clientId): static { $this->clientId = $clientId; return $this; }

    public function getClientSecret(): string { return $this->clientSecret; }
    public function setClientSecret(string $clientSecret): static { $this->clientSecret = $clientSecret; return $this; }

    public function isVerifyTls(): bool { return $this->verifyTls; }
    public function setVerifyTls(bool $verifyTls): static { $this->verifyTls = $verifyTls; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
}
