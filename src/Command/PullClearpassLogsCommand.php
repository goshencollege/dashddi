<?php

namespace App\Command;

use App\Repository\ClearpassServerRepository;
use App\Service\ClearpassAuthLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:pull-clearpass-logs',
    description: 'Pull authentication session logs from all configured ClearPass servers',
)]
class PullClearpassLogsCommand extends Command
{
    public function __construct(
        private readonly ClearpassAuthLogService   $logService,
        private readonly ClearpassServerRepository $serverRepo,
        private readonly EntityManagerInterface    $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'hours',
            null,
            InputOption::VALUE_REQUIRED,
            'On first run, pull logs from this many hours ago (default 24)',
            24,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $servers = $this->serverRepo->findBy([], ['name' => 'ASC']);
        $hours   = max(1, (int) $input->getOption('hours'));

        if (empty($servers)) {
            $io->note('No ClearPass servers configured — skipping.');
            return Command::SUCCESS;
        }

        $io->section('Pulling ClearPass authentication logs');
        $failed = false;

        foreach ($servers as $server) {
            $since = $server->getLastAuthLogPull()
                ?? new \DateTimeImmutable('-' . $hours . ' hours');

            $pullStarted = new \DateTimeImmutable();

            try {
                $result = $this->logService->pullFromServer($server, $since);

                $server->setLastAuthLogPull($pullStarted);
                $this->em->flush();

                $io->writeln(sprintf(
                    '  <info>✓</info> %s  imported=%d skipped=%d since=%s',
                    $server->getName(),
                    $result['imported'],
                    $result['skipped'],
                    $since->format('Y-m-d H:i'),
                ));

                foreach ($result['errors'] as $err) {
                    $io->writeln('    <comment>!</comment> ' . $err);
                    $failed = true;
                }
            } catch (\Throwable $e) {
                $io->error($server->getName() . ': ' . $e->getMessage());
                $failed = true;
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
