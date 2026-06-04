<?php

namespace App\Command;

use App\Entity\PushLog;
use App\Repository\DhcpServerRepository;
use App\Service\DhcpDeployService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-dhcp-config',
    description: 'Generate and deploy DHCP subnet JSON files from IPAM data',
)]
class GenerateDhcpConfigCommand extends Command
{
    public function __construct(
        private readonly DhcpDeployService     $deployer,
        private readonly DhcpServerRepository  $serverRepo,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output-dir', 'd', InputOption::VALUE_REQUIRED, 'Directory to write config files', '/tmp/dhcp')
            ->addOption('reload', null, InputOption::VALUE_NONE, 'Reload DHCP via the Control Agent after deploying')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $outputDir = rtrim((string) $input->getOption('output-dir'), '/');
        $reload    = (bool) $input->getOption('reload');

        try {
            $files = $this->deployer->generateFiles($outputDir);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        foreach ($files as $file) {
            $io->success(basename($file) . ' written → ' . $file);
        }

        $servers = $this->serverRepo->findBy([], ['name' => 'ASC']);

        if (empty($servers)) {
            $io->note('No DHCP servers configured — skipping deploy. Add servers via the web UI.');
            return Command::SUCCESS;
        }

        $io->section('Deploying');
        $failed = false;

        foreach ($servers as $server) {
            $startedAt = new \DateTimeImmutable();
            try {
                $results = $this->deployer->deployToServer($server, $reload);
                $success = $this->isSuccess($results);
                $error   = null;
            } catch (\Throwable $e) {
                $results = [];
                $success = false;
                $error   = $e->getMessage();
                $io->error($e->getMessage());
                $failed = true;
            }

            $this->em->persist(new PushLog('dhcp', $server->getName(), $success, $results, $startedAt, new \DateTimeImmutable(), $error));
            $this->em->flush();

            foreach ($results as $type => $result) {
                $label = match ($type) { 'dhcp4' => 'DHCPv4', 'dhcp6' => 'DHCPv6', 'global4' => 'Global Reservations (v4)', 'global6' => 'Global Reservations (v6)', default => 'DDNS' };

                if ($result['success']) {
                    $reloadNote = '';
                    if ($result['reload'] !== null) {
                        $r = $result['reload'];
                        if ($r['success']) {
                            $reloadNote = ' <info>(reloaded)</info>';
                        } else {
                            $reloadNote = sprintf(' <error>(reload failed: %s)</error>', $r['response']);
                            $failed = true;
                            if (!empty($r['restored'])) {
                                $reloadNote .= ' <comment>[previous config restored]</comment>';
                            } elseif (!empty($r['restore_error'])) {
                                $reloadNote .= sprintf(' <error>[restore failed: %s]</error>', $r['restore_error']);
                            }
                        }
                    }
                    $io->writeln(sprintf('  <info>✓</info> %s  %s → %s%s',
                        $server->getName(), $result['file'], $label, $reloadNote));
                } else {
                    $io->writeln(sprintf('  <error>✗</error> %s  %s → %s',
                        $server->getName(), $result['file'], $label));
                    if ($result['output']) {
                        $io->writeln('    ' . $result['output']);
                    }
                    $failed = true;
                }
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    private function isSuccess(array $result): bool
    {
        foreach ($result as $svc) {
            if (!$svc['success']) {
                return false;
            }
            if (isset($svc['reload']) && $svc['reload'] !== null && !$svc['reload']['success']) {
                return false;
            }
        }
        return true;
    }
}
