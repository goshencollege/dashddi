<?php

namespace App\Command;

use App\Repository\ScheduledTaskRepository;
use App\Service\ScheduledTaskRunnerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:run-scheduled-tasks',
    description: 'Check and execute any scheduled tasks that are currently due.',
)]
class RunScheduledTasksCommand extends Command
{
    public function __construct(
        private readonly ScheduledTaskRepository    $repo,
        private readonly ScheduledTaskRunnerService $runner,
        private readonly string                     $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        file_put_contents($this->projectDir . '/var/scheduler-heartbeat', (string) time());

        $tasks = $this->repo->findBy(['enabled' => true]);

        if (empty($tasks)) {
            $io->note('No enabled scheduled tasks.');
            return Command::SUCCESS;
        }

        $ran = 0;

        foreach ($tasks as $task) {
            if (!$this->runner->isDue($task)) {
                continue;
            }

            $io->writeln(sprintf('Running: <info>%s</info>', $task->getName()));
            $this->runner->run($task);

            $status = $task->getLastRunStatus();
            if ($status === 'success') {
                $io->writeln('  <info>✓ Success</info>');
            } else {
                $io->writeln('  <error>✗ Failed</error>');
            }

            $ran++;
        }

        if ($ran === 0) {
            $io->note('No tasks were due.');
        } else {
            $io->success(sprintf('Ran %d task(s).', $ran));
        }

        return Command::SUCCESS;
    }
}
