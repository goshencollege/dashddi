<?php

namespace App\Message;

final class PushClearpassMessage
{
    public function __construct(
        public readonly int $serverId,
        public readonly ?string $mac = null,
    ) {}
}
