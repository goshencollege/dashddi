<?php

namespace App\Message;

final class PushClearpassAllMessage
{
    public function __construct(
        public readonly int $serverId,
    ) {}
}
