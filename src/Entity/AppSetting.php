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

    public function getId(): int { return $this->id; }

    public function getDefaultLeaseRetentionDays(): ?int { return $this->defaultLeaseRetentionDays; }
    public function setDefaultLeaseRetentionDays(?int $days): static { $this->defaultLeaseRetentionDays = $days; return $this; }
}
