<?php

namespace App\Command;

use App\Repository\AppSettingRepository;
use App\Repository\HostRepository;
use App\Repository\NetworkInterfaceRepository;
use App\Service\InterfacePurgeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
        private readonly InterfacePurgeService       $interfacePurgeService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'now',
            null,
            InputOption::VALUE_NONE,
            'Purge every currently soft-deleted host/interface immediately, ignoring the configured retention period.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $now = (bool) $input->getOption('now');
        $days = null;

        if ($now) {
            $before = new \DateTimeImmutable();
        } else {
            $days = $this->settingRepo->getInstance()->getDeletedHostRetentionDays();
            if ($days === null) {
                $io->note('Deleted host retention is not set — nothing to purge.');
                return Command::SUCCESS;
            }
            $before = new \DateTimeImmutable("-{$days} days");
        }

        $ifaceCount = $this->interfacePurgeService->purgeMany($this->ifaceRepo->findDeletedBefore($before));
        $hostCount  = $this->hostRepo->purgeDeletedBefore($before);

        $io->success(sprintf(
            'Purged %d host(s) and %d interface(s) soft-deleted %s.',
            $hostCount,
            $ifaceCount,
            $now ? 'up to now' : "more than {$days} days ago",
        ));

        return Command::SUCCESS;
    }
}
