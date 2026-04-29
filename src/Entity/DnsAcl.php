<?php

namespace App\Entity;

use App\Repository\DnsAclRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DnsAclRepository::class)]
#[ORM\Table(name: 'dns_acl')]
#[UniqueEntity(fields: ['name'], message: 'This ACL name is already in use.')]
class DnsAcl
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

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $entries = null;

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getEntries(): array { return $this->entries ?? []; }
    public function setEntries(array $entries): static { $this->entries = $entries ?: null; return $this; }

    public function __toString(): string { return $this->name; }
}
