<?php

namespace App\Command;

use App\Repository\DnsServerRepository;
use App\Service\DnsDeployService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Wraps the 32-bit DNS serial space past a secondary's cached serial so that
 * normal date-based pushes are valid again.
 *
 * Background: DNS serial comparison uses RFC 1982 unsigned 32-bit arithmetic.
 * A serial S2 is "greater than" S1 if (S2 - S1) mod 2^32 is in [1, 2^31 - 1].
 * This means a small serial can be "greater than" a large one after a wrap.
 *
 * Strategy: push serials current+step, current+2*step, … (each mod 2^32).
 * Stop when the result drops below the target (today's YYYYMMDD01).  At that
 * point the wrapped value W satisfies (W - secondary_serial) mod 2^32 < 2^31,
 * so the secondary accepts it, and all subsequent date-based serials (which are
 * numerically > W) are also valid.
 *
 * Step must be less than 2^31 so each intermediate serial is "greater than" the
 * previous one in serial arithmetic (guaranteeing the secondary accepts each push).
 */
#[AsCommand(
    name: 'app:dns:bump-serial',
    description: 'Wrap the DNS serial past a secondary\'s cached value using RFC 1982 arithmetic',
)]
class BumpDnsSerialCommand extends Command
{
    private const UINT32_MOD = 4294967296; // 2^32
    private const MAX_SAFE_STEP = 2147483647; // 2^31 - 1

    public function __construct(
        private readonly DnsDeployService    $deployer,
        private readonly DnsServerRepository $serverRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'starting-serial',
                's',
                InputOption::VALUE_REQUIRED,
                'The secondary\'s current serial (run: dig SOA @<secondary-ns> <domain> +short)',
            )
            ->addOption(
                'target-serial',
                't',
                InputOption::VALUE_REQUIRED,
                'Stop when the serial drops below this value (default: today\'s YYYYMMDD01)',
            )
            ->addOption(
                'step',
                null,
                InputOption::VALUE_REQUIRED,
                'Amount to add each iteration (must be < 2^31 = 2147483648)',
                500_000_000,
            )
            ->addOption(
                'delay',
                null,
                InputOption::VALUE_REQUIRED,
                'Seconds to wait between pushes so the secondary can complete each zone transfer',
                10,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $startingSerial = (int) $input->getOption('starting-serial');
        if ($startingSerial <= 0) {
            $io->error([
                '--starting-serial is required.',
                'Find it with: dig SOA @<secondary-nameserver> <your-domain> +short',
                'The serial is the third field in the SOA response.',
            ]);
            return Command::FAILURE;
        }

        $target = $input->getOption('target-serial') !== null
            ? (int) $input->getOption('target-serial')
            : (int)(date('Ymd') . '01');

        $step  = (int) $input->getOption('step');
        $delay = (int) $input->getOption('delay');

        if ($step <= 0 || $step >= self::MAX_SAFE_STEP) {
            $io->error('--step must be between 1 and ' . (self::MAX_SAFE_STEP - 1) . '.');
            return Command::FAILURE;
        }

        $servers = array_values(array_filter(
            $this->serverRepo->findBy([], ['name' => 'ASC']),
            fn($s) => !$s->isSecondary(),
        ));

        if (empty($servers)) {
            $io->warning('No primary DNS servers configured.');
            return Command::SUCCESS;
        }

        $io->title('DNS Serial Bump');
        $io->definitionList(
            ['Secondary serial'    => $startingSerial],
            ['Target (stop below)' => $target],
            ['Step'                => number_format($step)],
            ['Delay between pushes' => $delay . 's'],
            ['Primary servers'     => implode(', ', array_map(fn($s) => $s->getName(), $servers))],
        );

        $io->text([
            'Each pushed serial will be greater than the previous in RFC 1982 arithmetic.',
            'Once the serial wraps below ' . $target . ', normal date-based pushes will be valid.',
        ]);
        $io->newLine();

        $current   = $startingSerial;
        $iteration = 0;

        do {
            $iteration++;
            $current = (int)(($current + $step) % self::UINT32_MOD);

            $io->section("Iteration $iteration — serial $current");

            $failed = false;

            foreach ($servers as $server) {
                try {
                    $results = $this->deployer->deployToServer($server, $current);
                } catch (\Throwable $e) {
                    $io->writeln('  <error>Failed</error>  ' . $server->getName() . ': ' . $e->getMessage());
                    $failed = true;
                    continue;
                }

                foreach ($results['views'] ?? [] as $viewName => $viewResult) {
                    foreach ($viewResult['zones'] ?? [] as $zoneName => $zone) {
                        if ($zone['success']) {
                            $io->writeln('  <info>OK</info>    ' . $zone['file']);
                        } else {
                            $io->writeln('  <error>FAIL</error>  ' . $zone['file'] . ': ' . $zone['output']);
                            $failed = true;
                        }
                    }
                }
            }

            if ($failed) {
                $io->error('Deploy failed — aborting to avoid pushing an intermediate serial without the secondary accepting it.');
                return Command::FAILURE;
            }

            if ($current < $target) {
                $io->success([
                    "Wrapped to serial $current, which is below target $target.",
                    'The secondary will accept this serial via RFC 1982 arithmetic.',
                    'All subsequent date-based pushes (serial >= ' . $target . ') will be valid.',
                ]);
                break;
            }

            $io->writeln("  Serial $current is still >= target $target. Waiting {$delay}s for the secondary to complete its zone transfer…");
            sleep($delay);
        } while (true);

        return Command::SUCCESS;
    }
}
