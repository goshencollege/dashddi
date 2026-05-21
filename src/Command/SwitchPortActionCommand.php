<?php

namespace App\Command;

use App\Repository\ArubaSwitchRepository;
use App\Service\ArubaCxService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:switch-port-action',
    description: 'Run a port action (reauth|bounce|poe-bounce) on an Aruba CX switch via SSH/REST',
)]
class SwitchPortActionCommand extends Command
{
    public function __construct(
        private readonly ArubaSwitchRepository $repo,
        private readonly ArubaCxService        $cx,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action',   InputArgument::REQUIRED, 'reauth, bounce, or poe-bounce')
            ->addArgument('switchIp', InputArgument::REQUIRED, 'Switch management IP')
            ->addArgument('portId',   InputArgument::REQUIRED, 'Port ID (e.g. 1/1/5)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $action   = $input->getArgument('action');
        $switchIp = $input->getArgument('switchIp');
        $portId   = $input->getArgument('portId');

        $creds = $this->repo->getInstance();
        if ($creds === null) {
            $io->error('No Aruba CX credentials configured.');
            return Command::FAILURE;
        }

        $io->section(sprintf('%s — %s on %s', $action, $portId, $switchIp));

        $result = match ($action) {
            'reauth'     => $this->cx->reauthenticatePort($creds, $switchIp, $portId),
            'bounce'     => $this->cx->bouncePort($creds, $switchIp, $portId),
            'poe-bounce' => $this->cx->poeBouncePort($creds, $switchIp, $portId),
            default      => null,
        };

        if ($result === null) {
            $io->error('Unknown action. Use: reauth, bounce, or poe-bounce');
            return Command::FAILURE;
        }

        if (!empty($result['output'])) {
            $io->writeln('<comment>SSH output:</comment>');
            $io->writeln($result['output']);
            $io->newLine();
        }

        if ($result['success']) {
            $io->success('Done.');
            return Command::SUCCESS;
        }

        $io->error($result['error'] ?? 'Unknown error');
        return Command::FAILURE;
    }
}
