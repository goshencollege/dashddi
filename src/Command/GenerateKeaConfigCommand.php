<?php

namespace App\Command;

use App\Repository\DhcpServerRepository;
use App\Service\KeaDeployService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-kea-config',
    description: 'Generate and deploy Kea DHCP subnet JSON files from IPAM data',
)]
class GenerateKeaConfigCommand extends Command
{
    public function __construct(
        private readonly KeaDeployService $deployer,
        private readonly DhcpServerRepository $serverRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output-dir', 'd', InputOption::VALUE_REQUIRED, 'Directory to write config files', '/tmp/kea')
            ->addOption('reload', null, InputOption::VALUE_NONE, 'Reload Kea via the Control Agent after deploying')
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
            $results = $this->deployer->deployToServer($server, $reload);

            foreach ($results as $type => $result) {
                $label = $type === 'dhcp4' ? 'DHCPv4' : 'DHCPv6';

                if ($result['success']) {
                    $reloadNote = '';
                    if ($result['reload'] !== null) {
                        $r = $result['reload'];
                        if ($r['success']) {
                            $reloadNote = sprintf(' <info>(%s ok)</info>', $r['stage']);
                        } else {
                            $reloadNote = sprintf(' <error>(%s failed: %s)</error>', $r['stage'], $r['response']);
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
}
