<?php

namespace App\Service;

use App\Entity\ScheduledTask;
use App\Repository\AppSettingRepository;
use Cron\CronExpression;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Process\Process;

class ScheduledTaskRunnerService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string                 $projectDir,
        private readonly SmtpMailerService      $mailer,
        private readonly AppSettingRepository   $settingRepo,
    ) {}

    public function isDue(ScheduledTask $task): bool
    {
        try {
            $cron = new CronExpression($task->getCronExpression());
        } catch (\InvalidArgumentException) {
            return false;
        }

        $tzName = $this->settingRepo->getInstance()->getTimezone() ?? 'UTC';
        $tz     = new \DateTimeZone($tzName);
        $now    = new \DateTime('now', $tz);

        if (!$cron->isDue($now)) {
            return false;
        }

        // Avoid re-running if already fired within this minute
        if ($task->getLastRunAt() !== null) {
            $minuteStart = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $now->format('Y-m-d H:i:00'), $tz);
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
        $process->setTimeout(null);
        $process->run();

        $status = $process->isSuccessful() ? 'success' : 'failure';
        $output = trim($process->getOutput() . "\n" . $process->getErrorOutput());

        $task->setLastRunAt(new \DateTimeImmutable());
        $task->setLastRunStatus($status);
        $task->setLastRunOutput($output);

        // Reconnect before flushing — the subprocess may have run for a long time
        // and the worker's database connection could have been dropped while idle.
        $this->em->getConnection()->close();
        $this->em->flush();

        if ($status === 'failure' && $task->getNotificationEmail() !== null && $this->mailer->isConfigured()) {
            try {
                $subject = sprintf('[DashDDI] Scheduled task failed: %s', $task->getName());
                $body    = sprintf(
                    "The scheduled task \"%s\" failed at %s.\n\nOutput:\n%s",
                    $task->getName(),
                    (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    $output ?: '(no output)',
                );
                $this->mailer->send($task->getNotificationEmail(), $subject, $body);
            } catch (\Throwable) {
                // Don't let a mail failure obscure the original task failure
            }
        }
    }
}
