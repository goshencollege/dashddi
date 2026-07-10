<?php

namespace App\Entity;

use App\Repository\DhcpLeaseRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DhcpLeaseRepository::class)]
#[ORM\Table(name: 'dhcp_lease')]
#[ORM\Index(columns: ['mac_address'], name: 'idx_dhcp_lease_mac')]
#[ORM\Index(columns: ['ip_address'], name: 'idx_dhcp_lease_ip')]
#[ORM\Index(columns: ['subnet_id'],  name: 'idx_dhcp_lease_subnet_id')]
#[ORM\Index(columns: ['dhcp_server_id'], name: 'idx_dhcp_lease_server_id')]
#[ORM\Index(columns: ['lease_start'], name: 'idx_dhcp_lease_start')]
class DhcpLease
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 17)]
    private string $macAddress;

    #[ORM\Column(length: 45)]
    private string $ipAddress;

    #[ORM\ManyToOne(targetEntity: Subnet::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Subnet $subnet = null;

    #[ORM\ManyToOne(targetEntity: DhcpServer::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DhcpServer $dhcpServer = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $hostname = null;

    #[ORM\Column]
    private \DateTimeImmutable $leaseStart;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $leaseExpires = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $macAddress, string $ipAddress)
    {
        $this->macAddress = strtolower($macAddress);
        $this->ipAddress  = $ipAddress;
        $this->leaseStart = new \DateTimeImmutable();
        $this->createdAt  = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getMacAddress(): string { return $this->macAddress; }
    public function getIpAddress(): string { return $this->ipAddress; }
    public function getSubnet(): ?Subnet { return $this->subnet; }
    public function setSubnet(?Subnet $subnet): static { $this->subnet = $subnet; return $this; }
    public function getDhcpServer(): ?DhcpServer { return $this->dhcpServer; }
    public function setDhcpServer(?DhcpServer $server): static { $this->dhcpServer = $server; return $this; }
    public function getHostname(): ?string { return $this->hostname; }
    public function setHostname(?string $hostname): static { $this->hostname = $hostname; return $this; }
    public function getLeaseStart(): \DateTimeImmutable { return $this->leaseStart; }
    public function setLeaseStart(\DateTimeImmutable $dt): static { $this->leaseStart = $dt; return $this; }
    public function getLeaseExpires(): ?\DateTimeImmutable { return $this->leaseExpires; }
    public function setLeaseExpires(?\DateTimeImmutable $dt): static { $this->leaseExpires = $dt; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
