<?php

namespace App\Entity;

use App\Entity\Trait\AuditableTrait;
use App\Repository\RadiusServerRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: RadiusServerRepository::class)]
#[ORM\Table(name: 'radius_server')]
class RadiusServer
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
    private string $remotePath = '/etc/freeradius';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $sshPrivateKey = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $sshPublicKey = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

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
    public function setSshPrivateKey(?string $key): static { $this->sshPrivateKey = $key; return $this; }

    public function getSshPublicKey(): ?string { return $this->sshPublicKey; }
    public function setSshPublicKey(?string $key): static { $this->sshPublicKey = $key; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
}
