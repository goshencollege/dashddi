<?php

namespace App\Entity;

use App\Repository\ActivityLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ActivityLogRepository::class)]
#[ORM\Table(name: 'activity_log')]
#[ORM\Index(columns: ['created_at'],       name: 'idx_activity_log_created')]
#[ORM\Index(columns: ['user_identifier'],  name: 'idx_activity_log_user')]
#[ORM\Index(columns: ['entity_type'],      name: 'idx_activity_log_type')]
class ActivityLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    /** 'create' | 'update' | 'soft_delete' | 'restore' | 'delete' | 'login' */
    #[ORM\Column(length: 16)]
    private string $action;

    /** Short class name: Host, Subnet, Domain, etc. Null for login events. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $entityType;

    /** Null for hard-deleted entities and login events. */
    #[ORM\Column(nullable: true)]
    private ?int $entityId;

    /** Human-readable name captured at log time. */
    #[ORM\Column(length: 255)]
    private string $entityLabel;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userIdentifier;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress;

    /** {fieldName: [oldValue, newValue]} — null for create/delete/login rows. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $changedFields;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $action,
        ?string $entityType,
        ?int $entityId,
        string $entityLabel,
        ?string $userIdentifier,
        ?string $ipAddress,
        ?array $changedFields,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->action          = $action;
        $this->entityType      = $entityType;
        $this->entityId        = $entityId;
        $this->entityLabel     = $entityLabel;
        $this->userIdentifier  = $userIdentifier;
        $this->ipAddress       = $ipAddress;
        $this->changedFields   = $changedFields;
        $this->createdAt       = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): int                 { return $this->id; }
    public function getAction(): string          { return $this->action; }
    public function getEntityType(): ?string     { return $this->entityType; }
    public function getEntityId(): ?int          { return $this->entityId; }
    public function getEntityLabel(): string     { return $this->entityLabel; }
    public function getUserIdentifier(): ?string { return $this->userIdentifier; }
    public function getIpAddress(): ?string      { return $this->ipAddress; }
    public function getChangedFields(): ?array   { return $this->changedFields; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
