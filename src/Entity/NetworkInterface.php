<?php

namespace App\Entity;

use App\Entity\Trait\AuditableTrait;
use App\Repository\NetworkInterfaceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: NetworkInterfaceRepository::class)]
#[ORM\Table(name: 'network_interface')]
class NetworkInterface
{
    use AuditableTrait;
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 17)]
    #[Assert\NotBlank]
    #[Assert\Regex(
        pattern: '/^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/',
        message: 'MAC address must be in format aa:bb:cc:dd:ee:ff'
    )]
    private string $macAddress = '';

    #[ORM\ManyToOne(targetEntity: Host::class, inversedBy: 'interfaces')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Host $host = null;

    #[ORM\ManyToOne(targetEntity: Subnet::class, inversedBy: 'interfaces')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Subnet $subnet = null;

    #[ORM\OneToOne(targetEntity: IpAddress::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: true)]
    private ?IpAddress $ipAddress = null;

    #[ORM\OneToOne(targetEntity: Ipv6Address::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: true)]
    private ?Ipv6Address $ipv6Address = null;

    #[ORM\OneToMany(targetEntity: InterfaceName::class, mappedBy: 'networkInterface', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $names;

    public function __construct()
    {
        $this->names = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getMacAddress(): string { return $this->macAddress; }
    public function setMacAddress(string $macAddress): static { $this->macAddress = strtolower($macAddress); return $this; }

    public function getHost(): ?Host { return $this->host; }
    public function setHost(?Host $host): static { $this->host = $host; return $this; }

    public function getSubnet(): ?Subnet { return $this->subnet; }
    public function setSubnet(?Subnet $subnet): static { $this->subnet = $subnet; return $this; }

    public function getIpAddress(): ?IpAddress { return $this->ipAddress; }
    public function setIpAddress(?IpAddress $ipAddress): static { $this->ipAddress = $ipAddress; return $this; }

    public function getIpv6Address(): ?Ipv6Address { return $this->ipv6Address; }
    public function setIpv6Address(?Ipv6Address $ipv6Address): static { $this->ipv6Address = $ipv6Address; return $this; }

    public function getNames(): Collection { return $this->names; }

    public function addName(InterfaceName $name): static
    {
        if (!$this->names->contains($name)) {
            $this->names->add($name);
            $name->setNetworkInterface($this);
        }
        return $this;
    }

    public function removeName(InterfaceName $name): static
    {
        if ($this->names->removeElement($name)) {
            if ($name->getNetworkInterface() === $this) {
                $name->setNetworkInterface(null);
            }
        }
        return $this;
    }

    public function getPrimaryName(): ?string
    {
        $first = $this->names->first();
        if (!$first) return null;
        return $first->getFullyQualifiedName();
    }

    public function __toString(): string
    {
        return $this->macAddress;
    }
}
