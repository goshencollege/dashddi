<?php

namespace App\Entity;

use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Repository\NetworkInterfaceRepository;
use App\Validator\UniqueMacAddress;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: NetworkInterfaceRepository::class)]
#[ORM\Table(name: 'network_interface')]
#[ORM\Index(columns: ['deleted_at'],      name: 'idx_network_interface_deleted_at')]
#[ORM\Index(columns: ['host_id'],         name: 'idx_network_interface_host_id')]
#[ORM\Index(columns: ['subnet_id'],       name: 'idx_network_interface_subnet_id')]
#[ORM\Index(columns: ['ip_address_id'],   name: 'idx_network_interface_ip_address_id')]
#[ORM\Index(columns: ['ipv6_address_id'], name: 'idx_network_interface_ipv6_address_id')]
#[UniqueMacAddress]
class NetworkInterface
{
    use AuditableTrait;
    use SoftDeletableTrait;
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $name = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(length: 17)]
    #[Assert\Regex(
        pattern: '/^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/',
        message: 'MAC address must be in format aa:bb:cc:dd:ee:ff'
    )]
    private string $macAddress = '00:00:00:00:00:00';

    #[ORM\ManyToOne(targetEntity: Host::class, inversedBy: 'interfaces')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Host $host = null;

    #[ORM\ManyToOne(targetEntity: Subnet::class, inversedBy: 'interfaces')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Subnet $subnet = null;

    #[ORM\OneToOne(targetEntity: IpAddress::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: true)]
    private ?IpAddress $ipAddress = null;

    #[ORM\OneToOne(targetEntity: Ipv6Address::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: true)]
    private ?Ipv6Address $ipv6Address = null;

    #[ORM\OneToMany(targetEntity: DomainRecord::class, mappedBy: 'networkInterface')]
    private Collection $domainRecords;

    public function __construct()
    {
        $this->domainRecords = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): static { $this->name = $name; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = $notes; return $this; }

    public function getMacAddress(): string { return $this->macAddress; }
    public function setMacAddress(string $macAddress): static
    {
        $hex = preg_replace('/[^0-9a-fA-F]/', '', $macAddress);
        if (strlen($hex)<12) {
            $hex = '000000000000';
        }
        $this->macAddress = strlen($hex) === 12
            ? implode(':', str_split(strtolower($hex), 2))
            : strtolower($macAddress);
        return $this;
    }

    public function getHost(): ?Host { return $this->host; }
    public function setHost(?Host $host): static { $this->host = $host; return $this; }

    public function getSubnet(): ?Subnet { return $this->subnet; }
    public function setSubnet(?Subnet $subnet): static { $this->subnet = $subnet; return $this; }

    public function getIpAddress(): ?IpAddress { return $this->ipAddress; }
    public function setIpAddress(?IpAddress $ipAddress): static { $this->ipAddress = $ipAddress; return $this; }

    public function getIpv6Address(): ?Ipv6Address { return $this->ipv6Address; }
    public function setIpv6Address(?Ipv6Address $ipv6Address): static { $this->ipv6Address = $ipv6Address; return $this; }

    public function getDomainRecords(): Collection { return $this->domainRecords; }

    public function getPrimaryName(): ?string
    {
        foreach ($this->domainRecords as $record) {
            if ($record->getDomain() !== null) {
                return $record->getFullyQualifiedHostname();
            }
        }
        return null;
    }

    public function __toString(): string
    {
        return $this->macAddress;
    }
}
