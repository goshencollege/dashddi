<?php

namespace App\Command;

use App\Repository\ActivityLogRepository;
use App\Repository\AppSettingRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:purge-activity-logs',
    description: 'Delete activity log entries older than the configured retention period.',
)]
class PurgeActivityLogsCommand extends Command
{
    public function __construct(
        private readonly ActivityLogRepository $logRepo,
        private readonly AppSettingRepository  $settingRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Retention period in days (overrides Application Settings)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $cliDays = $input->getOption('days');
        $days    = $cliDays !== null
            ? (int) $cliDays
            : $this->settingRepo->getInstance()->getActivityLogRetentionDays();

        if ($days === null) {
            $io->note('Activity log retention is not set — nothing to purge.');
            return Command::SUCCESS;
        }

        $before  = new \DateTimeImmutable("-{$days} days");
        $deleted = $this->logRepo->deleteOlderThan($before);

        $io->success(sprintf('Purged %d activity log record(s) older than %d days.', $deleted, $days));

        return Command::SUCCESS;
    }
}
