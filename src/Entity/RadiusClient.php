<?php

namespace App\Entity;

use App\Entity\Trait\AuditableTrait;
use App\Repository\RadiusClientRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: RadiusClientRepository::class)]
#[ORM\Table(name: 'radius_client')]
class RadiusClient
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[ORM\Column(length: 128)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 128)]
    private string $nasname = '';

    #[ORM\Column(length: 32, nullable: true)]
    #[Assert\Length(max: 32)]
    private ?string $shortname = null;

    /** Stored encrypted via EncryptedFieldSubscriber. */
    #[ORM\Column(type: Types::TEXT)]
    private string $secret = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private bool $enabled = true;

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getNasname(): string { return $this->nasname; }
    public function setNasname(string $nasname): static { $this->nasname = $nasname; return $this; }

    public function getShortname(): ?string { return $this->shortname; }
    public function setShortname(?string $shortname): static { $this->shortname = $shortname; return $this; }

    public function getSecret(): string { return $this->secret; }
    public function setSecret(string $secret): static { $this->secret = $secret; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): static { $this->enabled = $enabled; return $this; }
}
