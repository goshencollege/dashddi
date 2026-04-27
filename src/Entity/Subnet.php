<?php

namespace App\Entity;

use App\Entity\Trait\AuditableTrait;
use App\Repository\SubnetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SubnetRepository::class)]
#[ORM\Table(name: 'subnet')]
class Subnet
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

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Regex(
        pattern: '/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/',
        message: 'Must be a valid IPv4 CIDR (e.g. 192.168.1.0/24)'
    )]
    private ?string $ipv4Cidr = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Regex(
        pattern: '/^[0-9a-fA-F:]+\/\d{1,3}$/',
        message: 'Must be a valid IPv6 CIDR (e.g. 2001:db8::/64)'
    )]
    private ?string $ipv6Cidr = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 4094)]
    private ?int $vlan = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $gateway = null;

    #[ORM\OneToMany(targetEntity: IpAddress::class, mappedBy: 'subnet', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $ipAddresses;

    #[ORM\OneToMany(targetEntity: Ipv6Address::class, mappedBy: 'subnet', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $ipv6Addresses;

    #[ORM\OneToMany(targetEntity: NetworkInterface::class, mappedBy: 'subnet')]
    private Collection $interfaces;

    public function __construct()
    {
        $this->ipAddresses = new ArrayCollection();
        $this->ipv6Addresses = new ArrayCollection();
        $this->interfaces = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getIpv4Cidr(): ?string { return $this->ipv4Cidr; }
    public function setIpv4Cidr(?string $ipv4Cidr): static { $this->ipv4Cidr = $ipv4Cidr; return $this; }

    public function getIpv6Cidr(): ?string { return $this->ipv6Cidr; }
    public function setIpv6Cidr(?string $ipv6Cidr): static { $this->ipv6Cidr = $ipv6Cidr; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getVlan(): ?int { return $this->vlan; }
    public function setVlan(?int $vlan): static { $this->vlan = $vlan; return $this; }

    public function getGateway(): ?string { return $this->gateway; }
    public function setGateway(?string $gateway): static { $this->gateway = $gateway; return $this; }

    public function getIpAddresses(): Collection { return $this->ipAddresses; }
    public function getIpv6Addresses(): Collection { return $this->ipv6Addresses; }
    public function getInterfaces(): Collection { return $this->interfaces; }

    public function __toString(): string
    {
        $parts = [$this->name];
        if ($this->ipv4Cidr) $parts[] = $this->ipv4Cidr;
        if ($this->ipv6Cidr) $parts[] = $this->ipv6Cidr;
        return implode(' – ', $parts);
    }
}
