<?php

namespace App\Entity;

use App\Entity\Trait\AuditableTrait;
use App\Repository\HostRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: HostRepository::class)]
#[ORM\Table(name: 'host')]
class Host
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

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $location = null;

    #[ORM\OneToMany(targetEntity: NetworkInterface::class, mappedBy: 'host', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $interfaces;

    public function __construct()
    {
        $this->interfaces = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $location): static { $this->location = $location; return $this; }

    public function getInterfaces(): Collection { return $this->interfaces; }

    public function addInterface(NetworkInterface $interface): static
    {
        if (!$this->interfaces->contains($interface)) {
            $this->interfaces->add($interface);
            $interface->setHost($this);
        }
        return $this;
    }

    public function removeInterface(NetworkInterface $interface): static
    {
        if ($this->interfaces->removeElement($interface)) {
            if ($interface->getHost() === $this) {
                $interface->setHost(null);
            }
        }
        return $this;
    }

    public function __toString(): string { return $this->name; }
}
