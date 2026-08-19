<?php

namespace App\Entity;

use App\Enum\SwitchPortLogSource;
use App\Repository\SwitchPortLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only history of switch-port attachment observations for a
 * NetworkInterface, across every source that can learn one (ClearPass auth
 * log processing, the live AOS-CX switch scan). Never updated in place —
 * NetworkInterface.switchIp/switchPort/lastAuthAt is the fast "current"
 * cache both sources also keep current; this is the audit trail behind it.
 */
#[ORM\Entity(repositoryClass: SwitchPortLogRepository::class)]
#[ORM\Table(name: 'switch_port_log')]
#[ORM\Index(columns: ['network_interface_id'], name: 'idx_switch_port_log_iface')]
#[ORM\Index(columns: ['observed_at'],           name: 'idx_switch_port_log_observed_at')]
#[ORM\Index(columns: ['source'],                name: 'idx_switch_port_log_source')]
class SwitchPortLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: NetworkInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private NetworkInterface $networkInterface;

    #[ORM\Column(length: 20, enumType: SwitchPortLogSource::class)]
    private SwitchPortLogSource $source;

    #[ORM\Column(length: 45)]
    private string $switchIp;

    #[ORM\Column(length: 255)]
    private string $switchPort;

    #[ORM\Column]
    private \DateTimeImmutable $observedAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        NetworkInterface    $networkInterface,
        SwitchPortLogSource $source,
        string               $switchIp,
        string               $switchPort,
        \DateTimeImmutable   $observedAt,
    ) {
        $this->networkInterface = $networkInterface;
        $this->source           = $source;
        $this->switchIp         = $switchIp;
        $this->switchPort       = $switchPort;
        $this->observedAt       = $observedAt;
        $this->createdAt        = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getNetworkInterface(): NetworkInterface { return $this->networkInterface; }

    public function getSource(): SwitchPortLogSource { return $this->source; }

    public function getSwitchIp(): string { return $this->switchIp; }

    public function getSwitchPort(): string { return $this->switchPort; }

    public function getObservedAt(): \DateTimeImmutable { return $this->observedAt; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
