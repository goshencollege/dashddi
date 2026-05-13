<?php

namespace App\Message;

final class PushDnsMessage
{
    public function __construct(public readonly int $serverId) {}
}
