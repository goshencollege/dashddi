<?php

namespace App\Command;

use App\Repository\AppSettingRepository;
use App\Repository\DhcpLeaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:purge-dhcp-leases',
    description: 'Delete DHCP lease log entries that exceed each subnet\'s retention period.',
)]
class PurgeExpiredLeasesCommand extends Command
{
    public function __construct(
        private readonly DhcpLeaseRepository $leaseRepo,
        private readonly AppSettingRepository $settingRepo,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $defaultDays = $this->settingRepo->getInstance()->getDefaultLeaseRetentionDays();
        $deleted     = $this->leaseRepo->purgeByRetention($defaultDays);

        $io->success(sprintf('Purged %d expired DHCP lease record(s).', $deleted));

        return Command::SUCCESS;
    }
}
