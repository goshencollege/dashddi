<?php

namespace App\Entity;

use App\Entity\Trait\AuditableTrait;
use App\Repository\ArubaSwitchRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ArubaSwitchRepository::class)]
#[ORM\Table(name: 'aruba_switch')]
class ArubaSwitch
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
    private string $managementIp = '';

    #[ORM\Column(length: 128)]
    #[Assert\NotBlank]
    private string $username = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $password = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $sshPrivateKey = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $sshPublicKey = null;

    #[ORM\Column(length: 20, options: ['default' => 'v10.12'])]
    private string $restApiVersion = 'v10.12';

    #[ORM\Column(options: ['default' => false])]
    private bool $verifyTls = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getManagementIp(): string { return $this->managementIp; }
    public function setManagementIp(string $ip): static { $this->managementIp = $ip; return $this; }

    public function getUsername(): string { return $this->username; }
    public function setUsername(string $username): static { $this->username = $username; return $this; }

    public function getPassword(): ?string { return $this->password; }
    public function setPassword(?string $password): static { $this->password = $password ?: null; return $this; }

    public function getSshPrivateKey(): ?string { return $this->sshPrivateKey; }
    public function setSshPrivateKey(?string $key): static { $this->sshPrivateKey = $key; return $this; }

    public function getSshPublicKey(): ?string { return $this->sshPublicKey; }
    public function setSshPublicKey(?string $key): static { $this->sshPublicKey = $key; return $this; }

    public function getRestApiVersion(): string { return $this->restApiVersion; }
    public function setRestApiVersion(string $v): static { $this->restApiVersion = $v; return $this; }

    public function isVerifyTls(): bool { return $this->verifyTls; }
    public function setVerifyTls(bool $v): static { $this->verifyTls = $v; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): static { $this->description = $d; return $this; }
}
