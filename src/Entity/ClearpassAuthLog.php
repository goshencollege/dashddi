<?php

namespace App\Entity;

use App\Repository\ClearpassAuthLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ClearpassAuthLogRepository::class)]
#[ORM\Table(name: 'clearpass_auth_log')]
#[ORM\UniqueConstraint(name: 'uniq_clearpass_auth_session', columns: ['clearpass_server_id', 'session_id'])]
#[ORM\Index(columns: ['mac_address'], name: 'idx_clearpass_auth_mac')]
#[ORM\Index(columns: ['auth_timestamp'],      name: 'idx_clearpass_auth_timestamp')]
#[ORM\Index(columns: ['clearpass_server_id'], name: 'idx_clearpass_auth_server_id')]
#[ORM\Index(columns: ['auth_status'],         name: 'idx_clearpass_auth_status')]
#[ORM\Index(columns: ['role'],                name: 'idx_clearpass_auth_role')]
#[ORM\Index(columns: ['vlan'],                name: 'idx_clearpass_auth_vlan')]
#[ORM\Index(columns: ['auth_protocol'],       name: 'idx_clearpass_auth_protocol')]
#[ORM\Index(columns: ['service'],             name: 'idx_clearpass_auth_service')]
#[ORM\Index(columns: ['nas_ip'],              name: 'idx_clearpass_auth_nas_ip')]
class ClearpassAuthLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ClearpassServer::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ClearpassServer $clearpassServer = null;

    #[ORM\Column(length: 255)]
    private string $sessionId;

    #[ORM\Column(length: 17)]
    private string $macAddress;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $service = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $authStatus = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $authProtocol = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $nasIp = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nasPortId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $enforcementProfile = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $role = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $vlan = null;

    #[ORM\Column]
    private \DateTimeImmutable $authTimestamp;

    #[ORM\ManyToOne(targetEntity: NetworkInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?NetworkInterface $networkInterface = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $sessionId, string $macAddress, \DateTimeImmutable $authTimestamp)
    {
        $this->sessionId      = $sessionId;
        $this->macAddress     = strtolower($macAddress);
        $this->authTimestamp  = $authTimestamp;
        $this->createdAt      = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getClearpassServer(): ?ClearpassServer { return $this->clearpassServer; }
    public function setClearpassServer(?ClearpassServer $server): static { $this->clearpassServer = $server; return $this; }

    public function getSessionId(): string { return $this->sessionId; }

    public function getMacAddress(): string { return $this->macAddress; }

    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function setIpAddress(?string $ip): static { $this->ipAddress = $ip; return $this; }

    public function getUsername(): ?string { return $this->username; }
    public function setUsername(?string $username): static { $this->username = $username; return $this; }

    public function getService(): ?string { return $this->service; }
    public function setService(?string $service): static { $this->service = $service; return $this; }

    public function getAuthStatus(): ?string { return $this->authStatus; }
    public function setAuthStatus(?string $status): static { $this->authStatus = $status; return $this; }

    public function getAuthProtocol(): ?string { return $this->authProtocol; }
    public function setAuthProtocol(?string $protocol): static { $this->authProtocol = $protocol; return $this; }

    public function getNasIp(): ?string { return $this->nasIp; }
    public function setNasIp(?string $ip): static { $this->nasIp = $ip; return $this; }

    public function getNasPortId(): ?string { return $this->nasPortId; }
    public function setNasPortId(?string $portId): static { $this->nasPortId = $portId; return $this; }

    public function getEnforcementProfile(): ?string { return $this->enforcementProfile; }
    public function setEnforcementProfile(?string $profile): static { $this->enforcementProfile = $profile; return $this; }

    public function getRole(): ?string { return $this->role; }
    public function setRole(?string $role): static { $this->role = $role; return $this; }

    public function getVlan(): ?string { return $this->vlan; }
    public function setVlan(?string $vlan): static { $this->vlan = $vlan; return $this; }

    public function getAuthTimestamp(): \DateTimeImmutable { return $this->authTimestamp; }

    public function getNetworkInterface(): ?NetworkInterface { return $this->networkInterface; }
    public function setNetworkInterface(?NetworkInterface $iface): static { $this->networkInterface = $iface; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
