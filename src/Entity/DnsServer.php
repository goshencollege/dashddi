<?php

namespace App\Entity;

use App\Entity\Trait\AuditableTrait;
use App\Repository\DnsServerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DnsServerRepository::class)]
#[ORM\Table(name: 'dns_server')]
class DnsServer
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
    private string $hostname = '';

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    private string $sshUser = 'root';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $sshPrivateKey = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $sshPublicKey = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $remoteZonePath = '/etc/bind/zones';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToMany(targetEntity: DnsView::class)]
    #[ORM\JoinTable(name: 'dns_server_dns_view')]
    private Collection $views;

    public function __construct()
    {
        $this->views = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getHostname(): string { return $this->hostname; }
    public function setHostname(string $hostname): static { $this->hostname = $hostname; return $this; }

    public function getSshUser(): string { return $this->sshUser; }
    public function setSshUser(string $sshUser): static { $this->sshUser = $sshUser; return $this; }

    public function getSshPrivateKey(): ?string { return $this->sshPrivateKey; }
    public function setSshPrivateKey(?string $sshPrivateKey): static { $this->sshPrivateKey = $sshPrivateKey; return $this; }

    public function getSshPublicKey(): ?string { return $this->sshPublicKey; }
    public function setSshPublicKey(?string $sshPublicKey): static { $this->sshPublicKey = $sshPublicKey; return $this; }

    public function getRemoteZonePath(): string { return $this->remoteZonePath; }
    public function setRemoteZonePath(string $remoteZonePath): static { $this->remoteZonePath = $remoteZonePath; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

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
