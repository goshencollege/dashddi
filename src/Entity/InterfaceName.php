<?php

namespace App\Entity;

use App\Repository\InterfaceNameRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: InterfaceNameRepository::class)]
#[ORM\Table(name: 'interface_name')]
class InterfaceName
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[Assert\Regex(
        pattern: '/^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?$/',
        message: 'Name must be a valid DNS label (letters, digits, hyphens; no leading/trailing hyphen)'
    )]
    private string $name = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[Assert\Regex(
        pattern: '/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/',
        message: 'Must be a valid DNS domain name (e.g. example.com)'
    )]
    private string $dnsDomain = '';

    #[ORM\ManyToOne(targetEntity: NetworkInterface::class, inversedBy: 'names')]
    #[ORM\JoinColumn(nullable: false)]
    private ?NetworkInterface $networkInterface = null;

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getDnsDomain(): string { return $this->dnsDomain; }
    public function setDnsDomain(string $dnsDomain): static { $this->dnsDomain = $dnsDomain; return $this; }

    public function getNetworkInterface(): ?NetworkInterface { return $this->networkInterface; }
    public function setNetworkInterface(?NetworkInterface $networkInterface): static { $this->networkInterface = $networkInterface; return $this; }

    public function getFullyQualifiedName(): string
    {
        return $this->name . '.' . $this->dnsDomain;
    }

    public function __toString(): string { return $this->getFullyQualifiedName(); }
}
