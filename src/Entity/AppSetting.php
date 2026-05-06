<?php

namespace App\Entity;

use App\Repository\AppSettingRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AppSettingRepository::class)]
#[ORM\Table(name: 'app_setting')]
class AppSetting
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id = 1;

    #[ORM\Column(nullable: true)]
    private ?int $defaultLeaseRetentionDays = null;

    #[ORM\Column(nullable: true)]
    private ?int $defaultNewSubnetLeaseRetentionDays = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $smtpHost = null;

    #[ORM\Column(nullable: true)]
    private ?int $smtpPort = 587;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $smtpUsername = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $smtpPassword = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $smtpFromEmail = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $smtpFromName = null;

    #[ORM\Column(length: 10, nullable: true, options: ['default' => 'tls'])]
    private ?string $smtpEncryption = 'tls';

    public function getId(): int { return $this->id; }

    public function getDefaultLeaseRetentionDays(): ?int { return $this->defaultLeaseRetentionDays; }
    public function setDefaultLeaseRetentionDays(?int $days): static { $this->defaultLeaseRetentionDays = $days; return $this; }

    public function getDefaultNewSubnetLeaseRetentionDays(): ?int { return $this->defaultNewSubnetLeaseRetentionDays; }
    public function setDefaultNewSubnetLeaseRetentionDays(?int $days): static { $this->defaultNewSubnetLeaseRetentionDays = $days; return $this; }

    public function getSmtpHost(): ?string { return $this->smtpHost; }
    public function setSmtpHost(?string $smtpHost): static { $this->smtpHost = $smtpHost; return $this; }

    public function getSmtpPort(): ?int { return $this->smtpPort; }
    public function setSmtpPort(?int $smtpPort): static { $this->smtpPort = $smtpPort; return $this; }

    public function getSmtpUsername(): ?string { return $this->smtpUsername; }
    public function setSmtpUsername(?string $smtpUsername): static { $this->smtpUsername = $smtpUsername; return $this; }

    public function getSmtpPassword(): ?string { return $this->smtpPassword; }
    public function setSmtpPassword(?string $smtpPassword): static { $this->smtpPassword = $smtpPassword; return $this; }

    public function getSmtpFromEmail(): ?string { return $this->smtpFromEmail; }
    public function setSmtpFromEmail(?string $smtpFromEmail): static { $this->smtpFromEmail = $smtpFromEmail; return $this; }

    public function getSmtpFromName(): ?string { return $this->smtpFromName; }
    public function setSmtpFromName(?string $smtpFromName): static { $this->smtpFromName = $smtpFromName; return $this; }

    public function getSmtpEncryption(): ?string { return $this->smtpEncryption; }
    public function setSmtpEncryption(?string $smtpEncryption): static { $this->smtpEncryption = $smtpEncryption; return $this; }
}
