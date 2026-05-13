<?php

namespace App\Message;

final class PushDhcpMessage
{
    public function __construct(public readonly int $serverId) {}
}
