<?php

namespace App\Entity;

use App\Entity\Trait\AuditableTrait;
use App\Repository\DhcpServerRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DhcpServerRepository::class)]
#[ORM\Table(name: 'dhcp_server')]
class DhcpServer
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

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $remotePath = '/etc/kea';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $sshPrivateKey = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $sshPublicKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $controlUrl = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $controlUser = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $controlPassword = null;

    #[ORM\Column(length: 4)]
    private string $versionScope = 'both';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $ddnsEnabled = false;

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getHostname(): string { return $this->hostname; }
    public function setHostname(string $hostname): static { $this->hostname = $hostname; return $this; }

    public function getSshUser(): string { return $this->sshUser; }
    public function setSshUser(string $sshUser): static { $this->sshUser = $sshUser; return $this; }

    public function getRemotePath(): string { return $this->remotePath; }
    public function setRemotePath(string $remotePath): static { $this->remotePath = $remotePath; return $this; }

    public function getSshPrivateKey(): ?string { return $this->sshPrivateKey; }
    public function setSshPrivateKey(?string $sshPrivateKey): static { $this->sshPrivateKey = $sshPrivateKey; return $this; }

    public function getSshPublicKey(): ?string { return $this->sshPublicKey; }
    public function setSshPublicKey(?string $sshPublicKey): static { $this->sshPublicKey = $sshPublicKey; return $this; }

    public function getControlUrl(): ?string { return $this->controlUrl; }
    public function setControlUrl(?string $controlUrl): static { $this->controlUrl = $controlUrl ?: null; return $this; }

    public function getControlUser(): ?string { return $this->controlUser; }
    public function setControlUser(?string $controlUser): static { $this->controlUser = $controlUser ?: null; return $this; }

    public function getControlPassword(): ?string { return $this->controlPassword; }
    public function setControlPassword(?string $controlPassword): static { $this->controlPassword = $controlPassword ?: null; return $this; }

    public function getVersionScope(): string { return $this->versionScope; }
    public function setVersionScope(string $versionScope): static { $this->versionScope = $versionScope; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function isDdnsEnabled(): bool { return $this->ddnsEnabled; }
    public function setDdnsEnabled(bool $v): static { $this->ddnsEnabled = $v; return $this; }
}
