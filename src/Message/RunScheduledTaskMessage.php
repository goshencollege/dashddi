<?php

namespace App\Message;

final class RunScheduledTaskMessage
{
    public function __construct(public readonly int $taskId) {}
}
