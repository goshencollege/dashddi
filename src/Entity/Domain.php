<?php

namespace App\Entity;

use App\Entity\Trait\AuditableTrait;
use App\Repository\DomainRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DomainRepository::class)]
#[ORM\Table(name: 'domain')]
#[UniqueEntity(fields: ['name'], message: 'This domain name is already registered.')]
class Domain
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[Assert\Regex(
        pattern: '/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/',
        message: 'Must be a valid domain name (e.g. example.com)'
    )]
    private string $name = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\OneToMany(targetEntity: DomainRecord::class, mappedBy: 'domain', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['hostname' => 'ASC'])]
    private Collection $records;

    #[ORM\OneToMany(targetEntity: InterfaceName::class, mappedBy: 'domain')]
    private Collection $interfaceNames;

    public function __construct()
    {
        $this->records        = new ArrayCollection();
        $this->interfaceNames = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getRecords(): Collection { return $this->records; }
    public function getInterfaceNames(): Collection { return $this->interfaceNames; }

    public function __toString(): string { return $this->name; }
}
