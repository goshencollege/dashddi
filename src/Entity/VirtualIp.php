<?php

namespace App\Entity;

use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Enum\VirtualIpProtocol;
use App\Repository\VirtualIpRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: VirtualIpRepository::class)]
#[ORM\Table(name: 'virtual_ip')]
#[ORM\Index(columns: ['deleted_at'], name: 'idx_virtual_ip_deleted_at')]
#[ORM\Index(columns: ['subnet_id'],  name: 'idx_virtual_ip_subnet_id')]
class VirtualIp
{
    use AuditableTrait;
    use SoftDeletableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $label = '';

    #[ORM\Column(length: 20, enumType: VirtualIpProtocol::class)]
    private VirtualIpProtocol $protocol = VirtualIpProtocol::Vrrp;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 255)]
    private ?int $vrid = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\ManyToOne(targetEntity: Subnet::class, inversedBy: 'virtualIps')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Subnet $subnet = null;

    #[ORM\OneToOne(targetEntity: IpAddress::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: true)]
    private ?IpAddress $ipAddress = null;

    #[ORM\OneToOne(targetEntity: Ipv6Address::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: true)]
    private ?Ipv6Address $ipv6Address = null;

    #[ORM\ManyToMany(targetEntity: NetworkInterface::class)]
    #[ORM\JoinTable(name: 'virtual_ip_network_interface')]
    private Collection $memberInterfaces;

    #[ORM\OneToMany(targetEntity: DomainRecord::class, mappedBy: 'virtualIp', cascade: ['remove'])]
    private Collection $domainRecords;

    public function __construct()
    {
        $this->memberInterfaces = new ArrayCollection();
        $this->domainRecords    = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): static { $this->label = $label; return $this; }

    public function getProtocol(): VirtualIpProtocol { return $this->protocol; }
    public function setProtocol(VirtualIpProtocol $protocol): static { $this->protocol = $protocol; return $this; }

    public function getVrid(): ?int { return $this->vrid; }
    public function setVrid(?int $vrid): static { $this->vrid = $vrid; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = $notes; return $this; }

    public function getSubnet(): ?Subnet { return $this->subnet; }
    public function setSubnet(?Subnet $subnet): static { $this->subnet = $subnet; return $this; }

    public function getIpAddress(): ?IpAddress { return $this->ipAddress; }
    public function setIpAddress(?IpAddress $ipAddress): static { $this->ipAddress = $ipAddress; return $this; }

    public function getIpv6Address(): ?Ipv6Address { return $this->ipv6Address; }
    public function setIpv6Address(?Ipv6Address $ipv6Address): static { $this->ipv6Address = $ipv6Address; return $this; }

    public function getMemberInterfaces(): Collection { return $this->memberInterfaces; }

    public function addMemberInterface(NetworkInterface $interface): static
    {
        if (!$this->memberInterfaces->contains($interface)) {
            $this->memberInterfaces->add($interface);
        }
        return $this;
    }

    public function removeMemberInterface(NetworkInterface $interface): static
    {
        $this->memberInterfaces->removeElement($interface);
        return $this;
    }

    public function getDomainRecords(): Collection { return $this->domainRecords; }

    public function __toString(): string { return $this->label; }
}
