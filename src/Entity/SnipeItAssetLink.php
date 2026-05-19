<?php

namespace App\Entity;

use App\Repository\SnipeItAssetLinkRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tracks the mapping between a Snipe-IT asset and its managed Host in DashDDI.
 * Used to detect assets that have been deleted/archived so the host can be removed.
 */
#[ORM\Entity(repositoryClass: SnipeItAssetLinkRepository::class)]
#[ORM\Table(name: 'snipe_it_asset_link')]
#[ORM\UniqueConstraint(name: 'uq_snipe_asset', columns: ['server_id', 'snipe_asset_id'])]
class SnipeItAssetLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SnipeItServer::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?SnipeItServer $server = null;

    #[ORM\OneToOne(targetEntity: Host::class, inversedBy: 'snipeItAssetLink', cascade: ['remove'])]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Host $host = null;

    #[ORM\Column]
    private int $snipeAssetId = 0;

    #[ORM\Column(length: 255)]
    private string $snipeAssetTag = '';

    #[ORM\Column(length: 255)]
    private string $snipeAssetName = '';

    #[ORM\Column]
    private \DateTimeImmutable $syncedAt;

    /** True when this link was created by adopting a pre-existing DashDDI host rather than creating a new one. */
    #[ORM\Column(options: ['default' => false])]
    private bool $adopted = false;

    public function __construct()
    {
        $this->syncedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getServer(): ?SnipeItServer { return $this->server; }
    public function setServer(?SnipeItServer $server): static { $this->server = $server; return $this; }

    public function getHost(): ?Host { return $this->host; }
    public function setHost(?Host $host): static { $this->host = $host; return $this; }

    public function getSnipeAssetId(): int { return $this->snipeAssetId; }
    public function setSnipeAssetId(int $snipeAssetId): static { $this->snipeAssetId = $snipeAssetId; return $this; }

    public function getSnipeAssetTag(): string { return $this->snipeAssetTag; }
    public function setSnipeAssetTag(string $snipeAssetTag): static { $this->snipeAssetTag = $snipeAssetTag; return $this; }

    public function getSnipeAssetName(): string { return $this->snipeAssetName; }
    public function setSnipeAssetName(string $snipeAssetName): static { $this->snipeAssetName = $snipeAssetName; return $this; }

    public function getSyncedAt(): \DateTimeImmutable { return $this->syncedAt; }
    public function setSyncedAt(\DateTimeImmutable $syncedAt): static { $this->syncedAt = $syncedAt; return $this; }

    public function isAdopted(): bool { return $this->adopted; }
    public function setAdopted(bool $adopted): static { $this->adopted = $adopted; return $this; }
}
