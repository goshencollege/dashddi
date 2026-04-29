<?php

namespace App\Entity;

use App\Repository\DnssecPolicyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DnssecPolicyRepository::class)]
#[ORM\Table(name: 'dnssec_policy')]
#[UniqueEntity(fields: ['name'], message: 'This policy name is already in use.')]
class DnssecPolicy
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    private string $name = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $dnskeyTtl = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $maxZoneTtl = null;

    /** ISO 8601 duration, e.g. P14D */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $signaturesValidity = null;

    /** ISO 8601 duration, e.g. P5D */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $signaturesRefresh = null;

    /** Array of {type: ksk|zsk|csk, algorithm: string, lifetime: string} */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $keys = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $extraOptions = null;

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getDnskeyTtl(): ?int { return $this->dnskeyTtl; }
    public function setDnskeyTtl(?int $v): static { $this->dnskeyTtl = $v; return $this; }

    public function getMaxZoneTtl(): ?int { return $this->maxZoneTtl; }
    public function setMaxZoneTtl(?int $v): static { $this->maxZoneTtl = $v; return $this; }

    public function getSignaturesValidity(): ?string { return $this->signaturesValidity; }
    public function setSignaturesValidity(?string $v): static { $this->signaturesValidity = $v; return $this; }

    public function getSignaturesRefresh(): ?string { return $this->signaturesRefresh; }
    public function setSignaturesRefresh(?string $v): static { $this->signaturesRefresh = $v; return $this; }

    public function getKeys(): array { return $this->keys ?? []; }
    public function setKeys(array $keys): static { $this->keys = $keys ?: null; return $this; }

    public function getExtraOptions(): ?string { return $this->extraOptions; }
    public function setExtraOptions(?string $v): static { $this->extraOptions = $v; return $this; }

    public function __toString(): string { return $this->name; }
}
