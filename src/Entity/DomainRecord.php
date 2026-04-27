<?php

namespace App\Entity;

use App\Entity\Trait\AuditableTrait;
use App\Enum\RecordType;
use App\Repository\DomainRecordRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DomainRecordRepository::class)]
#[ORM\Table(name: 'domain_record')]
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

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $hostname = '';

    #[ORM\Column(length: 10, enumType: RecordType::class)]
    private RecordType $type = RecordType::A;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private string $value = '';

    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?int $ttl = null;

    public function getId(): ?int { return $this->id; }

    public function getDomain(): ?Domain { return $this->domain; }
    public function setDomain(?Domain $domain): static { $this->domain = $domain; return $this; }

    public function getHostname(): string { return $this->hostname; }
    public function setHostname(string $hostname): static { $this->hostname = $hostname; return $this; }

    public function getType(): RecordType { return $this->type; }
    public function setType(RecordType $type): static { $this->type = $type; return $this; }

    public function getValue(): string { return $this->value; }
    public function setValue(string $value): static { $this->value = $value; return $this; }

    public function getTtl(): ?int { return $this->ttl; }
    public function setTtl(?int $ttl): static { $this->ttl = $ttl; return $this; }
}
