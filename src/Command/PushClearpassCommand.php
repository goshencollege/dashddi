<?php

namespace App\Command;

use App\Entity\PushLog;
use App\Repository\ClearpassServerRepository;
use App\Service\ClearpassDeployService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:push-clearpass',
    description: 'Sync interface data to all configured ClearPass servers',
)]
class PushClearpassCommand extends Command
{
    public function __construct(
        private readonly ClearpassDeployService    $deployer,
        private readonly ClearpassServerRepository $serverRepo,
        private readonly EntityManagerInterface    $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('mac', null, InputOption::VALUE_REQUIRED, 'Push a single interface by MAC address (for testing)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $servers = $this->serverRepo->findBy([], ['name' => 'ASC']);

        if (empty($servers)) {
            $io->note('No ClearPass servers configured — skipping. Add servers via the web UI.');
            return Command::SUCCESS;
        }

        if ($mac = $input->getOption('mac')) {
            return $this->pushSingle($io, $servers, $mac);
        }

        $io->section('Syncing endpoints');
        $failed = false;

        foreach ($servers as $server) {
            $startedAt = new \DateTimeImmutable();
            try {
                $result  = $this->deployer->deployToServer($server);
                $success = $result['success'];
                $error   = null;
            } catch (\Throwable $e) {
                $result  = [];
                $success = false;
                $error   = $e->getMessage();
                $io->error($server->getName() . ': ' . $e->getMessage());
                $failed  = true;
            }

            $this->em->persist(new PushLog('clearpass', $server->getName(), $success, $result, $startedAt, new \DateTimeImmutable(), $error));
            $this->em->flush();

            if ($success) {
                $io->writeln(sprintf(
                    '  <info>✓</info> %s  created=%d updated=%d deleted=%d',
                    $server->getName(),
                    $result['created'] ?? 0,
                    $result['updated'] ?? 0,
                    $result['deleted'] ?? 0,
                ));
            } else {
                $failed = true;
                foreach ($result['errors'] ?? [] as $err) {
                    $io->writeln('  <error>✗</error> ' . $server->getName() . ': ' . $err);
                }
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    private function pushSingle(SymfonyStyle $io, array $servers, string $mac): int
    {
        $io->section('Pushing single interface: ' . $mac);
        $failed = false;

        foreach ($servers as $server) {
            try {
                $result = $this->deployer->pushSingleInterface($server, $mac);
                if ($result['success']) {
                    $io->writeln(sprintf('  <info>✓</info> %s  %s %s', $server->getName(), $result['action'], $result['mac']));
                    $io->writeln('  Response: ' . $result['response']);
                } else {
                    $io->writeln(sprintf('  <error>✗</error> %s  %s', $server->getName(), $result['error']));
                    $io->writeln('  Response: ' . $result['response']);
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
