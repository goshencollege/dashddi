<?php

namespace App\Message;

final class PullSnipeItMessage
{
    public function __construct(
        public readonly int $serverId,
    ) {}
}
