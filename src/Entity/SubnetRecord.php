<?php

namespace App\Entity;

use App\Entity\Trait\AuditableTrait;
use App\Enum\RecordType;
use App\Repository\SubnetRecordRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SubnetRecordRepository::class)]
#[ORM\Table(name: 'subnet_record')]
#[ORM\Index(columns: ['subnet_id'], name: 'idx_subnet_record_subnet_id')]
class SubnetRecord
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Subnet::class, inversedBy: 'records')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Subnet $subnet = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $hostname = '@';

    #[ORM\Column(length: 10, enumType: RecordType::class)]
    private RecordType $type = RecordType::NS;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private string $value = '';

    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?int $ttl = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    #[ORM\ManyToMany(targetEntity: DnsView::class)]
    #[ORM\JoinTable(name: 'subnet_record_dns_view')]
    private Collection $views;

    public function __construct()
    {
        $this->views = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getSubnet(): ?Subnet { return $this->subnet; }
    public function setSubnet(?Subnet $subnet): static { $this->subnet = $subnet; return $this; }

    public function getHostname(): string { return $this->hostname; }
    public function setHostname(string $hostname): static { $this->hostname = $hostname; return $this; }

    public function getType(): RecordType { return $this->type; }
    public function setType(RecordType $type): static { $this->type = $type; return $this; }

    public function getValue(): string { return $this->value; }
    public function setValue(string $value): static { $this->value = $value; return $this; }

    public function getTtl(): ?int { return $this->ttl; }
    public function setTtl(?int $ttl): static { $this->ttl = $ttl; return $this; }

    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $comment): static { $this->comment = $comment; return $this; }

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
}
