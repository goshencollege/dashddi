<?php

namespace App\Service;

use App\Entity\ScheduledTask;
use Cron\CronExpression;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Process\Process;

class ScheduledTaskRunnerService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $projectDir,
    ) {}

    public function isDue(ScheduledTask $task): bool
    {
        try {
            $cron = new CronExpression($task->getCronExpression());
        } catch (\InvalidArgumentException) {
            return false;
        }

        $now = new \DateTime();

        if (!$cron->isDue($now)) {
            return false;
        }

        // Avoid re-running if already fired within this minute
        if ($task->getLastRunAt() !== null) {
            $minuteStart = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $now->format('Y-m-d H:i:00'));
            if ($task->getLastRunAt() >= $minuteStart) {
                return false;
            }
        }

        return true;
    }

    public function run(ScheduledTask $task): void
    {
        $schedulable = $task->getTask();
        if ($schedulable === null) {
            return;
        }

        $consolePath = $this->projectDir . '/bin/console';
        $parts       = array_merge([$consolePath], explode(' ', $schedulable->consoleCommand()));
        $process     = new Process(array_merge(['php'], $parts));
        $process->setTimeout(300);
        $process->run();

        $task->setLastRunAt(new \DateTimeImmutable());
        $task->setLastRunStatus($process->isSuccessful() ? 'success' : 'failure');
        $task->setLastRunOutput(trim($process->getOutput() . "\n" . $process->getErrorOutput()));

        $this->em->flush();
    }
}
