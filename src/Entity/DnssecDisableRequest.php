<?php

namespace App\Entity;

use App\Enum\DnssecDisableStatus;
use App\Repository\DnssecDisableRequestRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DnssecDisableRequestRepository::class)]
#[ORM\Table(name: 'dnssec_disable_request')]
class DnssecDisableRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Domain::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Domain $domain = null;

    #[ORM\ManyToOne(targetEntity: Subnet::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Subnet $subnet = null;

    #[ORM\ManyToOne(targetEntity: DnsServer::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DnsServer $dnsServer = null;

    #[ORM\Column(length: 32, enumType: DnssecDisableStatus::class)]
    private DnssecDisableStatus $status = DnssecDisableStatus::AwaitingDsRemoval;

    /** DS record(s) as seen at request start, for the registrar-removal step. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $dsRecordsAtStart = null;

    /** Key file base names (no extension) that were retired in the KeysRetired step. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $retiredKeys = null;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** JSON array of {at: ISO8601, message: string} */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $log = null;

    public function __construct()
    {
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getDomain(): ?Domain { return $this->domain; }
    public function setDomain(?Domain $domain): static { $this->domain = $domain; return $this; }

    public function getSubnet(): ?Subnet { return $this->subnet; }
    public function setSubnet(?Subnet $subnet): static { $this->subnet = $subnet; return $this; }

    public function getZoneName(): string
    {
        if ($this->domain !== null) {
            return $this->domain->getName();
        }
        return $this->subnet?->getReverseZoneName() ?? '';
    }

    public function getEffectiveDnssecPolicy(): ?DnssecPolicy
    {
        return $this->domain?->getDnssecPolicy() ?? $this->subnet?->getDnssecPolicy();
    }

    public function getEffectiveKeyDirectory(): ?string
    {
        if (!$this->dnsServer || !$this->dnsServer->getKeyDirectory()) {
            return null;
        }
        $zone = $this->getZoneName();
        return $zone ? rtrim($this->dnsServer->getKeyDirectory(), '/') . '/' . $zone : null;
    }

    public function getEffectiveViews(): Collection
    {
        if ($this->domain !== null) {
            return $this->domain->getViews();
        }
        return $this->subnet?->getViews() ?? new ArrayCollection();
    }

    public function getDnsServer(): ?DnsServer { return $this->dnsServer; }
    public function setDnsServer(?DnsServer $server): static { $this->dnsServer = $server; return $this; }

    public function getStatus(): DnssecDisableStatus { return $this->status; }
    public function setStatus(DnssecDisableStatus $status): static { $this->status = $status; return $this; }

    public function getDsRecordsAtStart(): ?string { return $this->dsRecordsAtStart; }
    public function setDsRecordsAtStart(?string $v): static { $this->dsRecordsAtStart = $v; return $this; }

    /** @return string[] */
    public function getRetiredKeys(): array { return $this->retiredKeys ?? []; }
    /** @param string[] $keys */
    public function setRetiredKeys(array $keys): static { $this->retiredKeys = $keys; return $this; }

    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }

    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeImmutable $v): static { $this->completedAt = $v; return $this; }

    public function getLog(): array { return $this->log ?? []; }

    public function addLog(string $message): static
    {
        $this->log[] = ['at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM), 'message' => $message];
        return $this;
    }
}
