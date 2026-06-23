<?php

namespace App\Entity;

use App\Entity\Trait\AuditableTrait;
use App\Enum\RecordType;
use App\Repository\DomainRecordRepository;
use App\Validator\ViewsAllowedForDomainRecord;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: DomainRecordRepository::class)]
#[ORM\Table(name: 'domain_record')]
#[ORM\Index(columns: ['domain_id'], name: 'idx_domain_record_domain_id')]
#[ORM\Index(columns: ['network_interface_id'], name: 'idx_domain_record_network_interface_id')]
#[ViewsAllowedForDomainRecord]
class DomainRecord
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Domain::class, inversedBy: 'records')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Domain $domain = null;

    #[ORM\ManyToOne(targetEntity: NetworkInterface::class, inversedBy: 'domainRecords')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?NetworkInterface $networkInterface = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $hostname = '';

    #[ORM\Column(length: 10, enumType: RecordType::class)]
    private RecordType $type = RecordType::A;

    #[ORM\Column(type: 'text')]
    private string $value = '';

    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?int $ttl = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isCanonical = false;

    #[ORM\ManyToMany(targetEntity: DnsView::class)]
    #[ORM\JoinTable(name: 'domain_record_dns_view')]
    private Collection $views;

    public function __construct()
    {
        $this->views = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getDomain(): ?Domain { return $this->domain; }
    public function setDomain(?Domain $domain): static { $this->domain = $domain; return $this; }

    public function getNetworkInterface(): ?NetworkInterface { return $this->networkInterface; }
    public function setNetworkInterface(?NetworkInterface $networkInterface): static { $this->networkInterface = $networkInterface; return $this; }

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

    public function isCanonical(): bool { return $this->isCanonical; }
    public function setIsCanonical(bool $isCanonical): static { $this->isCanonical = $isCanonical; return $this; }

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

    public function getFullyQualifiedHostname(): string
    {
        if ($this->domain) {
            return $this->hostname . '.' . $this->domain->getName();
        }
        return $this->hostname;
    }

    #[Assert\Callback]
    public function validateValue(ExecutionContextInterface $context, mixed $payload): void
    {
        if ($this->networkInterface === null || !in_array($this->type, [RecordType::A, RecordType::AAAA], true)) {
            if ($this->value === '') {
                $context->buildViolation('This value should not be blank.')
                    ->atPath('value')
                    ->addViolation();
            }
        }
    }
}
