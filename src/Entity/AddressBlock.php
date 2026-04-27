<?php

namespace App\Entity;

use App\Entity\Trait\AuditableTrait;
use App\Enum\BlockType;
use App\Repository\AddressBlockRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AddressBlockRepository::class)]
#[ORM\Table(name: 'address_block')]
class AddressBlock
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Subnet::class, inversedBy: 'addressBlocks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Subnet $subnet = null;

    #[ORM\Column(length: 20, enumType: BlockType::class)]
    private BlockType $type = BlockType::Fixed;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $label = null;

    #[ORM\Column(length: 45)]
    #[Assert\NotBlank]
    private string $startIp = '';

    #[ORM\Column(length: 45)]
    #[Assert\NotBlank]
    private string $endIp = '';

    public function getId(): ?int { return $this->id; }

    public function getSubnet(): ?Subnet { return $this->subnet; }
    public function setSubnet(?Subnet $subnet): static { $this->subnet = $subnet; return $this; }

    public function getType(): BlockType { return $this->type; }
    public function setType(BlockType $type): static { $this->type = $type; return $this; }

    public function getLabel(): ?string { return $this->label; }
    public function setLabel(?string $label): static { $this->label = $label; return $this; }

    public function getStartIp(): string { return $this->startIp; }
    public function setStartIp(string $startIp): static { $this->startIp = $startIp; return $this; }

    public function getEndIp(): string { return $this->endIp; }
    public function setEndIp(string $endIp): static { $this->endIp = $endIp; return $this; }
}
