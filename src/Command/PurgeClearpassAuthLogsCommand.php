<?php

namespace App\Command;

use App\Repository\AppSettingRepository;
use App\Repository\ClearpassAuthLogRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:purge-clearpass-auth-logs',
    description: 'Delete ClearPass authentication log entries older than the configured retention period',
)]
class PurgeClearpassAuthLogsCommand extends Command
{
    public function __construct(
        private readonly ClearpassAuthLogRepository $logRepo,
        private readonly AppSettingRepository       $settingRepo,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $days = $this->settingRepo->getInstance()->getClearpassAuthLogRetentionDays() ?? 90;

        $cutoff  = new \DateTimeImmutable('-' . $days . ' days');
        $deleted = $this->logRepo->purgeOlderThan($cutoff);

        $io->success(sprintf('Purged %d ClearPass auth log record(s) older than %d days.', $deleted, $days));

        return Command::SUCCESS;
    }
}
