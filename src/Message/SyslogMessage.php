<?php

namespace App\Message;

final readonly class SyslogMessage
{
    public function __construct(
        public string $action,
        public ?string $entityType,
        public ?int $entityId,
        public string $entityLabel,
        public ?string $userIdentifier,
        public ?string $ipAddress,
        public ?array $changedFields,
        public \DateTimeImmutable $occurredAt,
    ) {}
}
