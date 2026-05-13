<?php

namespace App\Entity;

use App\Repository\PushLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PushLogRepository::class)]
#[ORM\Table(name: 'push_log')]
#[ORM\Index(columns: ['started_at'], name: 'idx_push_log_started')]
class PushLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 4)]
    private string $type;  // 'dns' | 'dhcp' (actually 4 chars max for 'dhcp')

    #[ORM\Column(length: 255)]
    private string $serverName;

    #[ORM\Column]
    private bool $success;

    #[ORM\Column(type: Types::JSON)]
    private array $result = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column]
    private \DateTimeImmutable $completedAt;

    public function __construct(
        string $type,
        string $serverName,
        bool $success,
        array $result,
        ?\DateTimeImmutable $startedAt = null,
        ?\DateTimeImmutable $completedAt = null,
        ?string $errorMessage = null,
    ) {
        $this->type         = $type;
        $this->serverName   = $serverName;
        $this->success      = $success;
        $this->result       = $result;
        $this->errorMessage = $errorMessage;
        $this->startedAt    = $startedAt ?? new \DateTimeImmutable();
        $this->completedAt  = $completedAt ?? new \DateTimeImmutable();
    }

    public function getId(): int { return $this->id; }
    public function getType(): string { return $this->type; }
    public function getServerName(): string { return $this->serverName; }
    public function isSuccess(): bool { return $this->success; }
    public function getResult(): array { return $this->result; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function getCompletedAt(): \DateTimeImmutable { return $this->completedAt; }

    public function getDurationMs(): int
    {
        return (int) (($this->completedAt->getTimestamp() - $this->startedAt->getTimestamp()) * 1000
            + ($this->completedAt->format('v') - $this->startedAt->format('v')));
    }
}
