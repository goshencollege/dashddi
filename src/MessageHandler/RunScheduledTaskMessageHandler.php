<?php

namespace App\MessageHandler;

use App\Message\RunScheduledTaskMessage;
use App\Repository\ScheduledTaskRepository;
use App\Service\ScheduledTaskRunnerService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RunScheduledTaskMessageHandler
{
    public function __construct(
        private readonly ScheduledTaskRepository    $repo,
        private readonly ScheduledTaskRunnerService $runner,
    ) {}

    public function __invoke(RunScheduledTaskMessage $message): void
    {
        $task = $this->repo->find($message->taskId);
        if ($task === null) {
            return;
        }

        $this->runner->run($task);
    }
}
