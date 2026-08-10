<?php

namespace App\Entity;

use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Repository\HostRepository;
use App\Validator\UniqueDuid;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;



#[ORM\Entity(repositoryClass: HostRepository::class)]
#[ORM\Table(name: 'host')]
#[ORM\Index(columns: ['deleted_at'], name: 'idx_host_deleted_at')]
#[UniqueDuid]
class Host
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
    private string $name = '';

    #[ORM\ManyToOne(targetEntity: Building::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Building $building = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    private ?string $room = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Regex(
        pattern: '/^([0-9a-fA-F]{2}:){1,64}[0-9a-fA-F]{2}$/',
        message: 'DUID must be a hex string (e.g. 00:01:00:01:2b:3c:4d:5e:aa:bb:cc:dd:ee:ff).'
    )]
    private ?string $duid = null;

    /**
     * DUID type labels as printed by tools like `networkctl status` (e.g.
     * "DUID-EN/Vendor:0000ab11..."), mapped to their RFC 8415 2-byte type code.
     * Checked longest/most-specific first so "DUID-LLT" isn't misread as "DUID-LL".
     */
    private const DUID_TYPE_LABELS = [
        'DUID-LLT'       => '0001',
        'DUID-EN/Vendor' => '0002',
        'DUID-EN'        => '0002',
        'DUID-LL'        => '0003',
        'DUID-UUID'      => '0004',
    ];

    #[ORM\OneToMany(targetEntity: NetworkInterface::class, mappedBy: 'host', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $interfaces;

    #[ORM\ManyToMany(targetEntity: Tag::class, inversedBy: 'hosts')]
    #[ORM\JoinTable(name: 'host_tag')]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $tags;

    #[ORM\OneToOne(mappedBy: 'host', targetEntity: SnipeItAssetLink::class)]
    private ?SnipeItAssetLink $snipeItAssetLink = null;

    #[ORM\OneToOne(mappedBy: 'host', targetEntity: ApiToken::class)]
    private ?ApiToken $apiToken = null;

    #[ORM\OneToMany(targetEntity: SshHostKey::class, mappedBy: 'host', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['algorithm' => 'ASC'])]
    private Collection $sshHostKeys;

    public function __construct()
    {
        $this->interfaces  = new ArrayCollection();
        $this->tags        = new ArrayCollection();
        $this->sshHostKeys = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getBuilding(): ?Building { return $this->building; }
    public function setBuilding(?Building $building): static { $this->building = $building; return $this; }

    public function getRoom(): ?string { return $this->room; }
    public function setRoom(?string $room): static { $this->room = $room; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = $notes; return $this; }

    public function getDuid(): ?string { return $this->duid; }
    public function setDuid(?string $duid): static
    {
        $duid = trim((string) $duid);
        if ($duid === '') {
            $this->duid = null;
            return $this;
        }

        $hex = null;
        foreach (self::DUID_TYPE_LABELS as $label => $typeCode) {
            $pos = stripos($duid, $label);
            if ($pos !== false) {
                $remainder = substr($duid, $pos + strlen($label));
                $hex = $typeCode . preg_replace('/[^0-9a-fA-F]/', '', $remainder);
                break;
            }
        }
        $hex ??= preg_replace('/[^0-9a-fA-F]/', '', $duid);

        $this->duid = (strlen($hex) >= 4 && strlen($hex) % 2 === 0)
            ? implode(':', str_split(strtolower($hex), 2))
            : strtolower($duid);
        return $this;
    }

    public function getLocation(): ?string
    {
        if (!$this->building) return null;
        return $this->building->getName() . ($this->room ?? '');
    }

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

    public function getInterfaces(): Collection { return $this->interfaces; }

    public function addInterface(NetworkInterface $interface): static
    {
        if (!$this->interfaces->contains($interface)) {
            $this->interfaces->add($interface);
            $interface->setHost($this);
        }
        return $this;
    }

    public function removeInterface(NetworkInterface $interface): static
    {
        if ($this->interfaces->removeElement($interface)) {
            if ($interface->getHost() === $this) {
                $interface->setHost(null);
            }
        }
        return $this;
    }

    public function getSnipeItAssetLink(): ?SnipeItAssetLink { return $this->snipeItAssetLink; }
    public function setSnipeItAssetLink(?SnipeItAssetLink $link): static { $this->snipeItAssetLink = $link; return $this; }

    public function getApiToken(): ?ApiToken { return $this->apiToken; }

    /** @return Collection<int, SshHostKey> */
    public function getSshHostKeys(): Collection { return $this->sshHostKeys; }

    public function getSshHostKeyByAlgorithm(string $algorithm): ?SshHostKey
    {
        foreach ($this->sshHostKeys as $key) {
            if ($key->getAlgorithm() === $algorithm) {
                return $key;
            }
        }
        return null;
    }

    /** Soft-deletes the host and cascades to all of its interfaces. */
    public function softDeleteWithInterfaces(): static
    {
        $this->softDelete();
        foreach ($this->interfaces as $iface) {
            if (!$iface->isDeleted()) {
                $iface->softDelete();
            }
        }
        return $this;
    }

    public function __toString(): string { return $this->name; }
}
