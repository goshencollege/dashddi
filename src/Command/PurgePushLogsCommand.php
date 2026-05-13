<?php

namespace App\Command;

use App\Repository\AppSettingRepository;
use App\Repository\PushLogRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:purge-push-logs',
    description: 'Delete push log entries older than the configured retention period.',
)]
class PurgePushLogsCommand extends Command
{
    public function __construct(
        private readonly PushLogRepository    $logRepo,
        private readonly AppSettingRepository $settingRepo,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $days = $this->settingRepo->getInstance()->getPushLogRetentionDays();

        if ($days === null) {
            $io->note('Push log retention is not set — nothing to purge.');
            return Command::SUCCESS;
        }

        $before  = new \DateTimeImmutable("-{$days} days");
        $deleted = $this->logRepo->deleteOlderThan($before);

        $io->success(sprintf('Purged %d push log record(s) older than %d days.', $deleted, $days));

        return Command::SUCCESS;
    }
}
