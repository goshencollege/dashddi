<?php

namespace App\Entity;

use App\Entity\Trait\AuditableTrait;
use App\Repository\SnipeItServerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SnipeItServerRepository::class)]
#[ORM\Table(name: 'snipe_it_server')]
class SnipeItServer
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $name = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Url]
    private string $apiUrl = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $apiKey = null;

    #[ORM\Column]
    private bool $verifyTls = true;

    /** Comma-separated list of Snipe-IT custom field display names that hold MAC addresses. */
    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(message: 'Enter at least one MAC address custom field name.')]
    private string $macCustomFields = '';

    /** Snipe-IT custom field display name whose value is a numeric VLAN ID used to override the category-based subnet assignment. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $vlanOverrideCustomField = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSyncAt = null;

    #[ORM\OneToMany(targetEntity: SnipeItCategorySubnetMap::class, mappedBy: 'server', cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['snipeCategoryName' => 'ASC'])]
    private Collection $categorySubnetMaps;

    public function __construct()
    {
        $this->categorySubnetMaps = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getApiUrl(): string { return $this->apiUrl; }
    public function setApiUrl(string $apiUrl): static { $this->apiUrl = rtrim($apiUrl, '/'); return $this; }

    public function getApiKey(): ?string { return $this->apiKey; }
    public function setApiKey(?string $apiKey): static { $this->apiKey = $apiKey; return $this; }

    public function isVerifyTls(): bool { return $this->verifyTls; }
    public function setVerifyTls(bool $verifyTls): static { $this->verifyTls = $verifyTls; return $this; }

    public function getMacCustomFields(): string { return $this->macCustomFields; }
    public function setMacCustomFields(string $macCustomFields): static { $this->macCustomFields = $macCustomFields; return $this; }

    /** Returns the configured field names as a trimmed array. */
    public function getMacCustomFieldNames(): array
    {
        return array_filter(array_map('trim', explode(',', $this->macCustomFields)));
    }

    public function getVlanOverrideCustomField(): ?string { return $this->vlanOverrideCustomField; }
    public function setVlanOverrideCustomField(?string $v): static { $this->vlanOverrideCustomField = $v ?: null; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getLastSyncAt(): ?\DateTimeImmutable { return $this->lastSyncAt; }
    public function setLastSyncAt(?\DateTimeImmutable $dt): static { $this->lastSyncAt = $dt; return $this; }

    /** Base web URL (strips /api/v1 suffix), used to build links to asset pages in the Snipe-IT UI. */
    public function getWebUrl(): string
    {
        return preg_replace('#/api/v1$#', '', $this->apiUrl);
    }

    /** @return Collection<int, SnipeItCategorySubnetMap> */
    public function getCategorySubnetMaps(): Collection { return $this->categorySubnetMaps; }
}
