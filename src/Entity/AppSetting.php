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

    #[ORM\Column(nullable: true, options: ['default' => 30])]
    private ?int $pushLogRetentionDays = 30;

    #[ORM\Column(nullable: true, options: ['default' => 30])]
    private ?int $clearpassAuthLogRetentionDays = 30;

    #[ORM\Column(nullable: true, options: ['default' => 90])]
    private ?int $switchPortLogRetentionDays = 90;

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

    #[ORM\Column(nullable: true, options: ['default' => 90])]
    private ?int $deletedHostRetentionDays = 90;

    #[ORM\Column(nullable: true, options: ['default' => 7])]
    private ?int $switchInfoMaxAgeDays = 7;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $timezone = null;

    #[ORM\Column(nullable: true, options: ['default' => 90])]
    private ?int $activityLogRetentionDays = 90;

    #[ORM\Column(options: ['default' => false])]
    private bool $syslogEnabled = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $syslogHost = null;

    #[ORM\Column(nullable: true, options: ['default' => 514])]
    private ?int $syslogPort = 514;

    #[ORM\Column(length: 3, nullable: true, options: ['default' => 'udp'])]
    private ?string $syslogProtocol = 'udp';

    #[ORM\Column(nullable: true, options: ['default' => 10])]
    private ?int $searchHistoryCount = 10;

    public function getId(): int { return $this->id; }

    public function getDefaultLeaseRetentionDays(): ?int { return $this->defaultLeaseRetentionDays; }
    public function setDefaultLeaseRetentionDays(?int $days): static { $this->defaultLeaseRetentionDays = $days; return $this; }

    public function getDefaultNewSubnetLeaseRetentionDays(): ?int { return $this->defaultNewSubnetLeaseRetentionDays; }
    public function setDefaultNewSubnetLeaseRetentionDays(?int $days): static { $this->defaultNewSubnetLeaseRetentionDays = $days; return $this; }

    public function getPushLogRetentionDays(): ?int { return $this->pushLogRetentionDays; }
    public function setPushLogRetentionDays(?int $days): static { $this->pushLogRetentionDays = $days; return $this; }

    public function getClearpassAuthLogRetentionDays(): ?int { return $this->clearpassAuthLogRetentionDays; }
    public function setClearpassAuthLogRetentionDays(?int $days): static { $this->clearpassAuthLogRetentionDays = $days; return $this; }

    public function getSwitchPortLogRetentionDays(): ?int { return $this->switchPortLogRetentionDays; }
    public function setSwitchPortLogRetentionDays(?int $days): static { $this->switchPortLogRetentionDays = $days; return $this; }

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

    public function getDeletedHostRetentionDays(): ?int { return $this->deletedHostRetentionDays; }
    public function setDeletedHostRetentionDays(?int $days): static { $this->deletedHostRetentionDays = $days; return $this; }

    public function getTimezone(): ?string { return $this->timezone; }
    public function setTimezone(?string $timezone): static { $this->timezone = $timezone; return $this; }

    public function getSwitchInfoMaxAgeDays(): ?int { return $this->switchInfoMaxAgeDays; }
    public function setSwitchInfoMaxAgeDays(?int $days): static { $this->switchInfoMaxAgeDays = $days; return $this; }

    public function getActivityLogRetentionDays(): ?int { return $this->activityLogRetentionDays; }
    public function setActivityLogRetentionDays(?int $days): static { $this->activityLogRetentionDays = $days; return $this; }

    public function isSyslogEnabled(): bool { return $this->syslogEnabled; }
    public function setSyslogEnabled(bool $v): static { $this->syslogEnabled = $v; return $this; }

    public function getSyslogHost(): ?string { return $this->syslogHost; }
    public function setSyslogHost(?string $host): static { $this->syslogHost = $host; return $this; }

    public function getSyslogPort(): ?int { return $this->syslogPort; }
    public function setSyslogPort(?int $port): static { $this->syslogPort = $port; return $this; }

    public function getSyslogProtocol(): ?string { return $this->syslogProtocol; }
    public function setSyslogProtocol(?string $protocol): static { $this->syslogProtocol = $protocol; return $this; }

    public function getSearchHistoryCount(): ?int { return $this->searchHistoryCount; }
    public function setSearchHistoryCount(?int $count): static { $this->searchHistoryCount = $count; return $this; }
}
