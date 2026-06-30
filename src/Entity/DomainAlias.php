<?php

namespace App\Entity;

use App\Repository\DomainAliasRepository;
use App\Validator\UniqueAliasName;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DomainAliasRepository::class)]
#[ORM\Table(name: 'domain_alias')]
#[UniqueAliasName]
class DomainAlias
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Domain::class, inversedBy: 'aliases')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Domain $domain;

    #[ORM\Column(length: 255, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[Assert\Regex(
        pattern: '/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/',
        message: 'Must be a valid domain name (e.g. example.net)'
    )]
    private string $name = '';

    public function getId(): ?int { return $this->id; }

    public function getDomain(): Domain { return $this->domain; }
    public function setDomain(Domain $domain): static { $this->domain = $domain; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
}
