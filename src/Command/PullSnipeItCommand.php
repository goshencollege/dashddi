<?php

namespace App\Command;

use App\Message\PushClearpassAllMessage;
use App\Repository\SnipeItServerRepository;
use App\Service\PushScopeService;
use App\Service\PushSuppressionContext;
use App\Service\SnipeItSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

#[AsCommand(
    name: 'app:pull-snipe-it',
    description: 'Pull assets from all configured Snipe-IT servers and sync hosts',
)]
class PullSnipeItCommand extends Command
{
    public function __construct(
        private readonly SnipeItSyncService      $syncService,
        private readonly SnipeItServerRepository $serverRepo,
        private readonly PushSuppressionContext  $suppression,
        private readonly PushScopeService        $pushScope,
        private readonly MessageBusInterface     $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('server-id', null, InputOption::VALUE_REQUIRED, 'Limit sync to a single Snipe-IT server by ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $serverId = $input->getOption('server-id');
        $servers  = $serverId !== null
            ? array_filter([$this->serverRepo->find((int) $serverId)])
            : $this->serverRepo->findBy([], ['name' => 'ASC']);

        if (empty($servers)) {
            $io->note('No Snipe-IT servers configured — skipping.');
            return Command::SUCCESS;
        }

        $io->section('Syncing Snipe-IT assets');
        $failed = false;

        $this->suppression->suppressClearpass();
        try {
            foreach ($servers as $server) {
                $io->writeln('  Syncing from <comment>' . $server->getName() . '</comment> …');
                try {
                    $result = $this->syncService->syncFromServer($server);
                    $io->writeln(sprintf(
                        '  <info>✓</info> %s  created=%d  updated=%d  deleted=%d  skipped=%d',
                        $server->getName(),
                        $result['created'],
                        $result['updated'],
                        $result['deleted'],
                        $result['skipped'],
                    ));
                    foreach ($result['errors'] as $err) {
                        $io->writeln('    <comment>!</comment> ' . $err);
                    }
                    if (!empty($result['errors'])) {
                        $failed = true;
                    }
                } catch (\Throwable $e) {
                    $io->error($server->getName() . ': ' . $e->getMessage());
                    $failed = true;
                }
            }
        } finally {
            $this->suppression->resumeClearpass();
        }

        foreach ($this->pushScope->allClearpassServerIds() as $serverId) {
            $this->bus->dispatch(
                new PushClearpassAllMessage($serverId),
                [new DeduplicateStamp('push_clearpass_' . $serverId . '_all', ttl: 3600)],
            );
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
