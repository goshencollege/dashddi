<?php

namespace App\Entity;

use App\Entity\Trait\AuditableTrait;
use App\Enum\TsigAlgorithm;
use App\Repository\DnsServerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: DnsServerRepository::class)]
#[ORM\Table(name: 'dns_server')]
class DnsServer
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $name = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Ip(version: 'all', message: 'Please enter a valid IPv4 or IPv6 address.')]
    private string $hostname = '';

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    private string $sshUser = 'root';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $sshPrivateKey = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $sshPublicKey = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $remoteZonePath = '/etc/bind/zones';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $keyDirectory = null;

    #[ORM\Column(length: 16)]
    private string $serverType = 'primary';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $primaryHostname = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 64)]
    private string $bindUser = 'bind';

    #[ORM\Column(length: 32, enumType: TsigAlgorithm::class, nullable: true)]
    private ?TsigAlgorithm $ddnsAlgorithm = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $ddnsSecret = null;

    #[ORM\ManyToMany(targetEntity: DnsView::class)]
    #[ORM\JoinTable(name: 'dns_server_dns_view')]
    private Collection $views;

    public function __construct()
    {
        $this->views = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getHostname(): string { return $this->hostname; }
    public function setHostname(string $hostname): static { $this->hostname = $hostname; return $this; }

    public function getSshUser(): string { return $this->sshUser; }
    public function setSshUser(string $sshUser): static { $this->sshUser = $sshUser; return $this; }

    public function getSshPrivateKey(): ?string { return $this->sshPrivateKey; }
    public function setSshPrivateKey(?string $sshPrivateKey): static { $this->sshPrivateKey = $sshPrivateKey; return $this; }

    public function getSshPublicKey(): ?string { return $this->sshPublicKey; }
    public function setSshPublicKey(?string $sshPublicKey): static { $this->sshPublicKey = $sshPublicKey; return $this; }

    public function getRemoteZonePath(): string { return $this->remoteZonePath; }
    public function setRemoteZonePath(string $remoteZonePath): static { $this->remoteZonePath = $remoteZonePath; return $this; }

    public function getKeyDirectory(): ?string { return $this->keyDirectory; }
    public function setKeyDirectory(?string $v): static { $this->keyDirectory = $v; return $this; }

    public function getServerType(): string { return $this->serverType; }
    public function setServerType(string $serverType): static { $this->serverType = $serverType; return $this; }
    public function isPrimary(): bool { return $this->serverType === 'primary'; }
    public function isSecondary(): bool { return $this->serverType === 'secondary'; }

    public function getPrimaryHostname(): ?string { return $this->primaryHostname; }
    public function setPrimaryHostname(?string $primaryHostname): static { $this->primaryHostname = $primaryHostname; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getBindUser(): string { return $this->bindUser; }
    public function setBindUser(string $v): static { $this->bindUser = $v; return $this; }

    public function getDdnsAlgorithm(): ?TsigAlgorithm { return $this->ddnsAlgorithm; }
    public function setDdnsAlgorithm(?TsigAlgorithm $v): static { $this->ddnsAlgorithm = $v; return $this; }

    public function getDdnsSecret(): ?string { return $this->ddnsSecret; }
    public function setDdnsSecret(?string $v): static { $this->ddnsSecret = $v; return $this; }

    /** TSIG key name used in BIND and Kea D2 configs, derived from the server name. */
    public function getDdnsKeyName(): string
    {
        return 'ddns-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($this->name));
    }

    public function getViews(): Collection { return $this->views; }

    public function addView(DnsView $view): static
    {
        if (!$this->views->contains($view)) {
            $this->views->add($view);
        }
        return $this;
    }

    public function removeView(DnsView $view): static
    {
        $this->views->removeElement($view);
        return $this;
    }

    #[Assert\Callback]
    public function validateSecondaryViewCount(ExecutionContextInterface $context): void
    {
        if ($this->isSecondary() && $this->views->count() > 1) {
            $context->buildViolation('A secondary server can only be assigned one view.')
                ->atPath('views')
                ->addViolation();
        }
    }
}
