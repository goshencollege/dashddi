<?php

namespace App\Entity;

use App\Repository\BackupSettingRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BackupSettingRepository::class)]
#[ORM\Table(name: 'backup_setting')]
class BackupSetting
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id = 1;

    /** 'local' or 'cifs' */
    #[ORM\Column(length: 10)]
    private string $destinationType = 'local';

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $localPath = null;

    /** e.g. //192.168.1.10/share */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cifsServer = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cifsUsername = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $cifsPassword = null;

    /** Subdirectory within the CIFS share, e.g. "backups" */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Regex(pattern: '/^[a-zA-Z0-9_\-\/\.]*$/', message: 'Subdirectory may only contain letters, numbers, hyphens, underscores, dots, and forward slashes.')]
    private ?string $cifsSubdir = null;

    /** Decrypt encrypted DB fields in the backup (produces human-readable SQL) */
    #[ORM\Column]
    private bool $decryptFields = false;

    /** Embed the APP_ENCRYPTION_KEY in a SQL comment header (when not decrypting) */
    #[ORM\Column]
    private bool $includeEncryptionKey = false;

    /** Encrypt the resulting backup file with AES-256-CBC + PBKDF2 */
    #[ORM\Column]
    private bool $encryptBackup = false;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $backupPassword = null;

    /** Omit the dhcp_lease table from backups */
    #[ORM\Column]
    private bool $excludeDhcpLeases = false;

    /** Omit the activity_log table from backups */
    #[ORM\Column]
    private bool $excludeActivityLog = false;

    /** Number of backup files to retain (0 = unlimited) */
    #[ORM\Column]
    private int $retentionCount = 10;

    public function getId(): int { return $this->id; }

    public function getDestinationType(): string { return $this->destinationType; }
    public function setDestinationType(string $t): static { $this->destinationType = $t; return $this; }

    public function getLocalPath(): ?string { return $this->localPath; }
    public function setLocalPath(?string $p): static { $this->localPath = $p; return $this; }

    public function getCifsServer(): ?string { return $this->cifsServer; }
    public function setCifsServer(?string $s): static { $this->cifsServer = $s; return $this; }

    public function getCifsUsername(): ?string { return $this->cifsUsername; }
    public function setCifsUsername(?string $u): static { $this->cifsUsername = $u; return $this; }

    public function getCifsPassword(): ?string { return $this->cifsPassword; }
    public function setCifsPassword(?string $p): static { $this->cifsPassword = $p; return $this; }

    public function getCifsSubdir(): ?string { return $this->cifsSubdir; }
    public function setCifsSubdir(?string $d): static { $this->cifsSubdir = $d; return $this; }

    public function isDecryptFields(): bool { return $this->decryptFields; }
    public function setDecryptFields(bool $v): static { $this->decryptFields = $v; return $this; }

    public function isIncludeEncryptionKey(): bool { return $this->includeEncryptionKey; }
    public function setIncludeEncryptionKey(bool $v): static { $this->includeEncryptionKey = $v; return $this; }

    public function isEncryptBackup(): bool { return $this->encryptBackup; }
    public function setEncryptBackup(bool $v): static { $this->encryptBackup = $v; return $this; }

    public function getBackupPassword(): ?string { return $this->backupPassword; }
    public function setBackupPassword(?string $p): static { $this->backupPassword = $p; return $this; }

    public function isExcludeDhcpLeases(): bool { return $this->excludeDhcpLeases; }
    public function setExcludeDhcpLeases(bool $v): static { $this->excludeDhcpLeases = $v; return $this; }

    public function isExcludeActivityLog(): bool { return $this->excludeActivityLog; }
    public function setExcludeActivityLog(bool $v): static { $this->excludeActivityLog = $v; return $this; }

    public function getRetentionCount(): int { return $this->retentionCount; }
    public function setRetentionCount(int $n): static { $this->retentionCount = $n; return $this; }
}
