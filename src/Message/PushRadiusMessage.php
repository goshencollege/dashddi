<?php

namespace App\Message;

final class PushRadiusMessage
{
    public function __construct(public readonly int $serverId) {}
}
