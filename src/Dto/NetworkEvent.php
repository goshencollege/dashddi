<?php

namespace App\Dto;

use DateTimeImmutable;

class NetworkEvent
{
    public function __construct(
        public readonly DateTimeImmutable $timestamp,
        public readonly string $type,
        public readonly object $entity,
    ) {}
}
