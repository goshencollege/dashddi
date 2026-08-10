<?php

namespace App\Entity;

use App\Entity\Trait\AuditableTrait;
use App\Entity\Domain;
use App\Entity\DnssecPolicy;
use App\Repository\AddressBlockRepository;
use App\Repository\SubnetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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
    private ?string $ipv4Cidr = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $ipv6Cidr = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 4094)]
    private ?int $vlan = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $gateway = null;

    #[ORM\ManyToOne(targetEntity: Vrf::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Vrf $vrf = null;

    #[ORM\OneToMany(targetEntity: IpAddress::class, mappedBy: 'subnet', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $ipAddresses;

    #[ORM\OneToMany(targetEntity: Ipv6Address::class, mappedBy: 'subnet', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $ipv6Addresses;

    #[ORM\OneToMany(targetEntity: NetworkInterface::class, mappedBy: 'subnet')]
    private Collection $interfaces;

    #[ORM\OneToMany(targetEntity: VirtualIp::class, mappedBy: 'subnet', cascade: ['remove'], orphanRemoval: true)]
    private Collection $virtualIps;

    #[ORM\OneToMany(targetEntity: AddressBlock::class, mappedBy: 'subnet', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['startIp' => 'ASC'])]
    private Collection $addressBlocks;

    #[ORM\OneToMany(targetEntity: SubnetRecord::class, mappedBy: 'subnet', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['hostname' => 'ASC', 'type' => 'ASC'])]
    private Collection $records;

    #[ORM\ManyToMany(targetEntity: Tag::class, inversedBy: 'subnets')]
    #[ORM\JoinTable(name: 'subnet_tag')]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $tags;

    #[ORM\ManyToMany(targetEntity: DnsView::class)]
    #[ORM\JoinTable(name: 'subnet_dns_view')]
    private Collection $views;

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

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $defaultTtl = 3600;

    #[ORM\ManyToOne(targetEntity: DnssecPolicy::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DnssecPolicy $dnssecPolicy = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $leaseRetentionDays = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isContainer = false;

    #[ORM\ManyToOne(targetEntity: Domain::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Domain $ddnsDomain = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $reverseZoneAggregatesV4 = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $reverseZoneAggregatesV6 = false;

    public function __construct()
    {
        $this->ipAddresses = new ArrayCollection();
        $this->ipv6Addresses = new ArrayCollection();
        $this->interfaces  = new ArrayCollection();
        $this->virtualIps  = new ArrayCollection();
        $this->addressBlocks = new ArrayCollection();
        $this->tags    = new ArrayCollection();
        $this->views   = new ArrayCollection();
        $this->records = new ArrayCollection();
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

    public function getVrf(): ?Vrf { return $this->vrf; }
    public function setVrf(?Vrf $vrf): static { $this->vrf = $vrf; return $this; }

    public function getIpAddresses(): Collection { return $this->ipAddresses; }
    public function getIpv6Addresses(): Collection { return $this->ipv6Addresses; }
    public function getInterfaces(): Collection { return $this->interfaces; }
    public function getVirtualIps(): Collection { return $this->virtualIps; }
    public function getAddressBlocks(): Collection { return $this->addressBlocks; }
    public function getRecords(): Collection { return $this->records; }
    public function getSoaNameserver(): ?string { return $this->soaNameserver; }
    public function setSoaNameserver(?string $v): static { $this->soaNameserver = $v; return $this; }

    public function getSoaEmail(): ?string { return $this->soaEmail; }
    public function setSoaEmail(?string $v): static { $this->soaEmail = $v; return $this; }

    public function getSoaRefresh(): ?int { return $this->soaRefresh; }
    public function setSoaRefresh(?int $v): static { $this->soaRefresh = $v; return $this; }

    public function getSoaRetry(): ?int { return $this->soaRetry; }
    public function setSoaRetry(?int $v): static { $this->soaRetry = $v; return $this; }

    public function getSoaExpire(): ?int { return $this->soaExpire; }
    public function setSoaExpire(?int $v): static { $this->soaExpire = $v; return $this; }

    public function getSoaTtl(): ?int { return $this->soaTtl; }
    public function setSoaTtl(?int $v): static { $this->soaTtl = $v; return $this; }

    public function getDefaultTtl(): ?int { return $this->defaultTtl; }
    public function setDefaultTtl(?int $v): static { $this->defaultTtl = $v; return $this; }

    public function getDnssecPolicy(): ?DnssecPolicy { return $this->dnssecPolicy; }
    public function setDnssecPolicy(?DnssecPolicy $v): static { $this->dnssecPolicy = $v; return $this; }

    public function getLeaseRetentionDays(): ?int { return $this->leaseRetentionDays; }
    public function setLeaseRetentionDays(?int $v): static { $this->leaseRetentionDays = $v; return $this; }

    public function isContainer(): bool { return $this->isContainer; }
    public function setIsContainer(bool $isContainer): static { $this->isContainer = $isContainer; return $this; }

    public function getDdnsDomain(): ?Domain { return $this->ddnsDomain; }
    public function setDdnsDomain(?Domain $v): static { $this->ddnsDomain = $v; return $this; }

    public function isDdnsEnabled(): bool { return $this->ddnsDomain !== null; }
    public function getDdnsDnsServer(): ?DnsServer { return $this->ddnsDomain?->getDdnsDnsServer(); }
    public function getDdnsQualifyingSuffix(): ?string { return $this->ddnsDomain?->getName(); }

    public function getTags(): Collection { return $this->tags; }

    public function addTag(Tag $tag): static
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }
        return $this;
    }

    public function removeTag(Tag $tag): static
    {
        $this->tags->removeElement($tag);
        return $this;
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

    public function isReverseZoneAggregatesV4(): bool { return $this->reverseZoneAggregatesV4; }
    public function setReverseZoneAggregatesV4(bool $v): static { $this->reverseZoneAggregatesV4 = $v; return $this; }

    public function isReverseZoneAggregatesV6(): bool { return $this->reverseZoneAggregatesV6; }
    public function setReverseZoneAggregatesV6(bool $v): static { $this->reverseZoneAggregatesV6 = $v; return $this; }

    public function getReverseZoneName(): ?string
    {
        if ($this->ipv4Cidr !== null) {
            return $this->ipv4ReverseZone($this->ipv4Cidr);
        }
        if ($this->ipv6Cidr !== null) {
            return $this->ipv6ReverseZone($this->ipv6Cidr);
        }
        return null;
    }

    private function ipv4ReverseZone(string $cidr): string
    {
        [$ip, $prefix] = explode('/', $cidr, 2);
        $octets = explode('.', $ip);
        $count  = (int) ceil((int) $prefix / 8);
        $parts  = array_reverse(array_slice($octets, 0, max(1, $count)));
        return implode('.', $parts) . '.in-addr.arpa';
    }

    private function ipv6ReverseZone(string $cidr): string
    {
        [$ip, $prefix] = explode('/', $cidr, 2);
        $hex     = bin2hex(inet_pton($ip));
        $nibbles = str_split($hex);
        $count   = (int) ceil((int) $prefix / 4);
        $parts   = array_reverse(array_slice($nibbles, 0, max(1, $count)));
        return implode('.', $parts) . '.ip6.arpa';
    }

    #[Assert\Callback]
    public function validateIpv4Cidr(ExecutionContextInterface $context): void
    {
        if ($this->ipv4Cidr === null) {
            return;
        }
        $parts = explode('/', $this->ipv4Cidr, 2);
        if (count($parts) !== 2) {
            $context->buildViolation('Must be a valid IPv4 CIDR (e.g. 192.168.1.0/24)')
                ->atPath('ipv4Cidr')->addViolation();
            return;
        }
        [$addr, $prefix] = $parts;
        $prefixInt = (int) $prefix;
        if (!filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || (string) $prefixInt !== $prefix
            || $prefixInt < 0
            || $prefixInt > 32
        ) {
            $context->buildViolation('Must be a valid IPv4 CIDR (e.g. 192.168.1.0/24)')
                ->atPath('ipv4Cidr')->addViolation();
        }
    }

    #[Assert\Callback]
    public function validateIpv6Cidr(ExecutionContextInterface $context): void
    {
        if ($this->ipv6Cidr === null) {
            return;
        }
        $parts = explode('/', $this->ipv6Cidr, 2);
        if (count($parts) !== 2) {
            $context->buildViolation('Must be a valid IPv6 CIDR (e.g. 2001:db8::/64)')
                ->atPath('ipv6Cidr')->addViolation();
            return;
        }
        [$addr, $prefix] = $parts;
        $prefixInt = (int) $prefix;
        if (!filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            || (string) $prefixInt !== $prefix
            || $prefixInt < 0
            || $prefixInt > 128
        ) {
            $context->buildViolation('Must be a valid IPv6 CIDR (e.g. 2001:db8::/64)')
                ->atPath('ipv6Cidr')->addViolation();
        }
    }

    public function __toString(): string
    {
        $parts = [$this->name];
        if ($this->ipv4Cidr) $parts[] = $this->ipv4Cidr;
        if ($this->ipv6Cidr) $parts[] = $this->ipv6Cidr;
        return implode(' – ', $parts);
    }
}
