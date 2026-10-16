<?php

namespace App\Message;

final class PurgeInterfaceLeaseMessage
{
    public function __construct(
        public readonly ?string $ipv4,
        public readonly ?string $ipv6,
    ) {}
}
