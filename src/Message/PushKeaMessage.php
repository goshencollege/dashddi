<?php

namespace App\Message;

final class PushKeaMessage
{
    public function __construct(public readonly int $serverId) {}
}
