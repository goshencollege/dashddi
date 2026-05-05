<?php

namespace App\Entity;

use App\Repository\TagRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TagRepository::class)]
#[ORM\Table(name: 'tag')]
class Tag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    private string $name = '';

    #[ORM\ManyToMany(targetEntity: Host::class, mappedBy: 'tags')]
    private Collection $hosts;

    #[ORM\ManyToMany(targetEntity: Subnet::class, mappedBy: 'tags')]
    private Collection $subnets;

    public function __construct()
    {
        $this->hosts = new ArrayCollection();
        $this->subnets = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getHosts(): Collection { return $this->hosts; }

    public function getSubnets(): Collection { return $this->subnets; }

    public function __toString(): string { return $this->name; }
}
