<?php

namespace App\Command;

use App\Repository\ClearpassServerRepository;
use App\Service\ClearpassAuthLogService;
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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('debug', null, InputOption::VALUE_NONE, 'Dump the first raw session record and exit (useful for inspecting field names)');
        $this->addOption('mac', null, InputOption::VALUE_REQUIRED, 'Filter debug output to sessions matching this MAC address');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $servers = $this->serverRepo->findBy([], ['name' => 'ASC']);

        if (empty($servers)) {
            $io->note('No ClearPass servers configured — skipping.');
            return Command::SUCCESS;
        }

        if ($input->getOption('debug')) {
            $server = $servers[0];
            $mac    = (string) ($input->getOption('mac') ?? '');
            $io->section('Probing API endpoints on: ' . $server->getName() . ($mac !== '' ? ' (mac: ' . $mac . ')' : ''));
            try {
                $probes = $this->logService->probeEndpoints($server, $mac);
                foreach ($probes as $path => $result) {
                    $io->writeln('<comment>' . $path . '</comment>');
                    $io->writeln(is_string($result)
                        ? '  <error>' . $result . '</error>'
                        : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    $io->newLine();
                }
            } catch (\Throwable $e) {
                $io->error($e->getMessage());
                return Command::FAILURE;
            }
            return Command::SUCCESS;
        }

        $io->section('Pulling ClearPass authentication logs');
        $failed = false;

        foreach ($servers as $server) {
            try {
                $result = $this->logService->pullFromServer($server);

                $io->writeln(sprintf(
                    '  <info>✓</info> %s  imported=%d  updated=%d',
                    $server->getName(),
                    $result['imported'],
                    $result['updated'],
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
