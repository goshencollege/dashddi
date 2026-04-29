<?php

namespace App\Entity;

use App\Enum\KskRolloverStatus;
use App\Repository\DnssecKskRolloverRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DnssecKskRolloverRepository::class)]
#[ORM\Table(name: 'dnssec_ksk_rollover')]
class DnssecKskRollover
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Domain::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Domain $domain;

    #[ORM\ManyToOne(targetEntity: DnsServer::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DnsServer $dnsServer = null;

    #[ORM\Column(length: 32, enumType: KskRolloverStatus::class)]
    private KskRolloverStatus $status = KskRolloverStatus::KeyPublished;

    /** Algorithm string passed to dnssec-keygen, e.g. ecdsap256sha256 */
    #[ORM\Column(length: 32)]
    private string $algorithm = '';

    #[ORM\Column(length: 255)]
    private string $keyDirectory = '';

    /** Key file base name without extension, e.g. Kexample.com.+013+12345 */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $oldKeyFile = null;

    #[ORM\Column(nullable: true)]
    private ?int $oldKeyTag = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $newKeyFile = null;

    #[ORM\Column(nullable: true)]
    private ?int $newKeyTag = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $dsRecord = null;

    /** DNSKEY TTL in seconds, used to calculate wait times */
    #[ORM\Column(nullable: true)]
    private ?int $dnskeyTtlSeconds = null;

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

    public function getDomain(): Domain { return $this->domain; }
    public function setDomain(Domain $domain): static { $this->domain = $domain; return $this; }

    public function getDnsServer(): ?DnsServer { return $this->dnsServer; }
    public function setDnsServer(?DnsServer $server): static { $this->dnsServer = $server; return $this; }

    public function getStatus(): KskRolloverStatus { return $this->status; }
    public function setStatus(KskRolloverStatus $status): static { $this->status = $status; return $this; }

    public function getAlgorithm(): string { return $this->algorithm; }
    public function setAlgorithm(string $v): static { $this->algorithm = $v; return $this; }

    public function getKeyDirectory(): string { return $this->keyDirectory; }
    public function setKeyDirectory(string $v): static { $this->keyDirectory = $v; return $this; }

    public function getOldKeyFile(): ?string { return $this->oldKeyFile; }
    public function setOldKeyFile(?string $v): static { $this->oldKeyFile = $v; return $this; }

    public function getOldKeyTag(): ?int { return $this->oldKeyTag; }
    public function setOldKeyTag(?int $v): static { $this->oldKeyTag = $v; return $this; }

    public function getNewKeyFile(): ?string { return $this->newKeyFile; }
    public function setNewKeyFile(?string $v): static { $this->newKeyFile = $v; return $this; }

    public function getNewKeyTag(): ?int { return $this->newKeyTag; }
    public function setNewKeyTag(?int $v): static { $this->newKeyTag = $v; return $this; }

    public function getDsRecord(): ?string { return $this->dsRecord; }
    public function setDsRecord(?string $v): static { $this->dsRecord = $v; return $this; }

    public function getDnskeyTtlSeconds(): ?int { return $this->dnskeyTtlSeconds; }
    public function setDnskeyTtlSeconds(?int $v): static { $this->dnskeyTtlSeconds = $v; return $this; }

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
