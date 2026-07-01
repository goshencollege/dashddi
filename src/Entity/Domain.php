<?php

namespace App\Entity;

use App\Entity\Trait\AuditableTrait;
use App\Repository\DomainRepository;
use App\Entity\DnssecPolicy;
use App\Entity\DomainAlias;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DomainRepository::class)]
#[ORM\Table(name: 'domain')]
#[UniqueEntity(fields: ['name'], message: 'This domain name is already registered.')]
class Domain
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[Assert\Regex(
        pattern: '/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/',
        message: 'Must be a valid domain name (e.g. example.com)'
    )]
    private string $name = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $soaNameserver = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Assert\Email]
    private ?string $soaEmail = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $soaRefresh = 3600;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $soaRetry = 900;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $soaExpire = 604800;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $soaTtl = 3600;

    #[ORM\ManyToOne(targetEntity: DnssecPolicy::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DnssecPolicy $dnssecPolicy = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $ddnsEnabled = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $excludeFromInterfaces = false;

    #[ORM\ManyToOne(targetEntity: DnsServer::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DnsServer $ddnsDnsServer = null;

    #[ORM\OneToMany(targetEntity: DomainRecord::class, mappedBy: 'domain', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['hostname' => 'ASC'])]
    private Collection $records;

    #[ORM\ManyToMany(targetEntity: DnsView::class)]
    #[ORM\JoinTable(name: 'domain_dns_view')]
    private Collection $views;

    #[ORM\OneToMany(targetEntity: DomainAlias::class, mappedBy: 'domain', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $aliases;

    public function __construct()
    {
        $this->records = new ArrayCollection();
        $this->views   = new ArrayCollection();
        $this->aliases = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getSoaNameserver(): ?string { return $this->soaNameserver; }
    public function setSoaNameserver(?string $soaNameserver): static { $this->soaNameserver = $soaNameserver; return $this; }

    public function getSoaEmail(): ?string { return $this->soaEmail; }
    public function setSoaEmail(?string $soaEmail): static { $this->soaEmail = $soaEmail; return $this; }

    public function getSoaRefresh(): ?int { return $this->soaRefresh; }
    public function setSoaRefresh(?int $soaRefresh): static { $this->soaRefresh = $soaRefresh; return $this; }

    public function getSoaRetry(): ?int { return $this->soaRetry; }
    public function setSoaRetry(?int $soaRetry): static { $this->soaRetry = $soaRetry; return $this; }

    public function getSoaExpire(): ?int { return $this->soaExpire; }
    public function setSoaExpire(?int $soaExpire): static { $this->soaExpire = $soaExpire; return $this; }

    public function getSoaTtl(): ?int { return $this->soaTtl; }
    public function setSoaTtl(?int $soaTtl): static { $this->soaTtl = $soaTtl; return $this; }

    public function getDnssecPolicy(): ?DnssecPolicy { return $this->dnssecPolicy; }
    public function setDnssecPolicy(?DnssecPolicy $v): static { $this->dnssecPolicy = $v; return $this; }

    public function isDdnsEnabled(): bool { return $this->ddnsEnabled; }
    public function setDdnsEnabled(bool $v): static { $this->ddnsEnabled = $v; return $this; }

    public function isExcludeFromInterfaces(): bool { return $this->excludeFromInterfaces; }
    public function setExcludeFromInterfaces(bool $v): static { $this->excludeFromInterfaces = $v; return $this; }

    public function getDdnsDnsServer(): ?DnsServer { return $this->ddnsDnsServer; }
    public function setDdnsDnsServer(?DnsServer $v): static { $this->ddnsDnsServer = $v; return $this; }

    public function getRecords(): Collection { return $this->records; }

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

    /** @return Collection<int, DomainAlias> */
    public function getAliases(): Collection { return $this->aliases; }

    public function addAlias(DomainAlias $alias): static
    {
        if (!$this->aliases->contains($alias)) {
            $this->aliases->add($alias);
            $alias->setDomain($this);
        }
        return $this;
    }

    public function removeAlias(DomainAlias $alias): static
    {
        $this->aliases->removeElement($alias);
        return $this;
    }

    public function __toString(): string { return $this->name; }
}
