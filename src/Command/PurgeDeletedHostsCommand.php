<?php

namespace App\Command;

use App\Repository\AppSettingRepository;
use App\Repository\HostRepository;
use App\Repository\NetworkInterfaceRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:purge-deleted-hosts',
    description: 'Hard-delete soft-deleted hosts and interfaces older than the configured retention period.',
)]
class PurgeDeletedHostsCommand extends Command
{
    public function __construct(
        private readonly HostRepository              $hostRepo,
        private readonly NetworkInterfaceRepository  $ifaceRepo,
        private readonly AppSettingRepository        $settingRepo,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $days = $this->settingRepo->getInstance()->getDeletedHostRetentionDays();

        if ($days === null) {
            $io->note('Deleted host retention is not set — nothing to purge.');
            return Command::SUCCESS;
        }

        $before = new \DateTimeImmutable("-{$days} days");

        $ifaceCount = $this->ifaceRepo->purgeDeletedBefore($before);
        $hostCount  = $this->hostRepo->purgeDeletedBefore($before);

        $io->success(sprintf(
            'Purged %d host(s) and %d interface(s) soft-deleted more than %d days ago.',
            $hostCount,
            $ifaceCount,
            $days,
        ));

        return Command::SUCCESS;
    }
}
