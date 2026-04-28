<?php

namespace App\Command;

use App\Service\KeaDeployService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-kea-config',
    description: 'Generate Kea DHCP4/DHCP6 subnet JSON files from IPAM data',
)]
class GenerateKeaConfigCommand extends Command
{
    public function __construct(
        private readonly KeaDeployService $deployer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output-dir', 'd', InputOption::VALUE_REQUIRED, 'Directory to write config files', '/tmp/kea')
            ->addOption('scp-target', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'SCP destination(s): user@host:/remote/path  (repeatable)')
            ->addOption('ssh-key', 'i', InputOption::VALUE_REQUIRED, 'SSH private key for SCP')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $outputDir  = rtrim((string) $input->getOption('output-dir'), '/');
        $scpTargets = (array) $input->getOption('scp-target');
        $sshKey     = $input->getOption('ssh-key');

        try {
            $files = $this->deployer->generateFiles($outputDir);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        foreach ($files as $file) {
            $io->success(basename($file) . ' written → ' . $file);
        }

        if ($scpTargets) {
            $io->section('Deploying via SCP');
            $failed = false;

            foreach ($scpTargets as $target) {
                foreach ($files as $file) {
                    $dest     = rtrim($target, '/') . '/' . basename($file);
                    $exitCode = $this->deployer->scpFile($file, $dest, $sshKey, $scpOutput);
                    if ($exitCode === 0) {
                        $io->writeln(sprintf('  <info>✓</info> %s → %s', basename($file), $target));
                    } else {
                        $io->writeln(sprintf('  <error>✗</error> %s → %s', basename($file), $target));
                        if ($scpOutput) {
                            $io->writeln("    $scpOutput");
                        }
                        $failed = true;
                    }
                }
            }

            return $failed ? Command::FAILURE : Command::SUCCESS;
        }

        return Command::SUCCESS;
    }
}
