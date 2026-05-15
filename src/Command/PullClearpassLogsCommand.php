<?php

namespace App\Command;

use App\Repository\ClearpassServerRepository;
use App\Service\ClearpassAuthLogService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
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
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $servers = $this->serverRepo->findBy([], ['name' => 'ASC']);

        if (empty($servers)) {
            $io->note('No ClearPass servers configured — skipping.');
            return Command::SUCCESS;
        }

        $io->section('Pulling ClearPass authentication logs');
        $failed = false;

        foreach ($servers as $server) {
            try {
                $result = $this->logService->pullFromServer($server);

                $io->writeln(sprintf(
                    '  <info>✓</info> %s  imported=%d',
                    $server->getName(),
                    $result['imported'],
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
