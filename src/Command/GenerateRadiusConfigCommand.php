<?php

namespace App\Command;

use App\Entity\PushLog;
use App\Repository\RadiusServerRepository;
use App\Service\RadiusDeployService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-radius-config',
    description: 'Generate and deploy FreeRADIUS clients.conf to all configured RADIUS servers',
)]
class GenerateRadiusConfigCommand extends Command
{
    public function __construct(
        private readonly RadiusDeployService    $deployer,
        private readonly RadiusServerRepository $serverRepo,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $servers = $this->serverRepo->findBy([], ['name' => 'ASC']);

        if (empty($servers)) {
            $io->note('No RADIUS servers configured — skipping deploy. Add servers via the web UI.');
            return Command::SUCCESS;
        }

        $io->section('Deploying');
        $failed = false;

        foreach ($servers as $server) {
            $startedAt = new \DateTimeImmutable();
            try {
                $results = $this->deployer->deployToServer($server);
                $success = $this->isSuccess($results);
                $error   = null;
            } catch (\Throwable $e) {
                $results = [];
                $success = false;
                $error   = $e->getMessage();
                $io->error($e->getMessage());
                $failed  = true;
            }

            $this->em->persist(new PushLog('radius', $server->getName(), $success, $results, $startedAt, new \DateTimeImmutable(), $error));
            $this->em->flush();

            foreach ($results as $file) {
                if ($file['success']) {
                    $reloadNote = '';
                    if ($file['reload'] !== null) {
                        $reloadNote = $file['reload']['success']
                            ? ' <info>(reloaded)</info>'
                            : sprintf(' <error>(reload failed: %s)</error>', $file['reload']['output']);
                        if (!$file['reload']['success']) {
                            $failed = true;
                        }
                    }
                    $io->writeln(sprintf('  <info>✓</info> %s  %s%s', $server->getName(), $file['file'], $reloadNote));
                } else {
                    $io->writeln(sprintf('  <error>✗</error> %s  %s', $server->getName(), $file['file']));
                    if ($file['output']) {
                        $io->writeln('    ' . $file['output']);
                    }
                    $failed = true;
                }
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    private function isSuccess(array $result): bool
    {
        foreach ($result as $file) {
            if (!$file['success']) {
                return false;
            }
            if (isset($file['reload']) && $file['reload'] !== null && !$file['reload']['success']) {
                return false;
            }
        }
        return true;
    }
}
