<?php

namespace App\Entity;

use App\Repository\IpAddressRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: IpAddressRepository::class)]
#[ORM\Table(name: 'ip_address')]
class IpAddress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Ip(version: 4, message: 'Must be a valid IPv4 address')]
    private string $address = '';

    #[ORM\ManyToOne(targetEntity: Subnet::class, inversedBy: 'ipAddresses')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Subnet $subnet = null;

    public function getId(): ?int { return $this->id; }

    public function getAddress(): string { return $this->address; }
    public function setAddress(string $address): static { $this->address = $address; return $this; }

    public function getSubnet(): ?Subnet { return $this->subnet; }
    public function setSubnet(?Subnet $subnet): static { $this->subnet = $subnet; return $this; }

    public function __toString(): string { return $this->address; }
}
