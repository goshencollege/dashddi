<?php

namespace App\Entity;

use App\Repository\SnipeItCategorySubnetMapRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SnipeItCategorySubnetMapRepository::class)]
#[ORM\Table(name: 'snipe_it_category_subnet_map')]
#[ORM\UniqueConstraint(name: 'uq_server_category', columns: ['server_id', 'snipe_category_id'])]
class SnipeItCategorySubnetMap
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SnipeItServer::class, inversedBy: 'categorySubnetMaps')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?SnipeItServer $server = null;

    #[ORM\Column]
    #[Assert\Positive(message: 'Enter the numeric Snipe-IT category ID.')]
    private int $snipeCategoryId = 0;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $snipeCategoryName = '';

    #[ORM\ManyToOne(targetEntity: Subnet::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Subnet $subnet = null;

    public function getId(): ?int { return $this->id; }

    public function getServer(): ?SnipeItServer { return $this->server; }
    public function setServer(?SnipeItServer $server): static { $this->server = $server; return $this; }

    public function getSnipeCategoryId(): int { return $this->snipeCategoryId; }
    public function setSnipeCategoryId(int $id): static { $this->snipeCategoryId = $id; return $this; }

    public function getSnipeCategoryName(): string { return $this->snipeCategoryName; }
    public function setSnipeCategoryName(string $name): static { $this->snipeCategoryName = $name; return $this; }

    public function getSubnet(): ?Subnet { return $this->subnet; }
    public function setSubnet(?Subnet $subnet): static { $this->subnet = $subnet; return $this; }
}
