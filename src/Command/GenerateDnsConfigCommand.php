<?php

namespace App\Command;

use App\Entity\PushLog;
use App\Repository\DnsServerRepository;
use App\Service\DnsConfigGenerator;
use App\Service\DnsDeployService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:generate-dns-config', description: 'Generate and optionally deploy BIND zone files and dashddi.conf')]
class GenerateDnsConfigCommand extends Command
{
    public function __construct(
        private readonly DnsConfigGenerator    $generator,
        private readonly DnsDeployService      $deployer,
        private readonly DnsServerRepository   $serverRepo,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output-dir', 'd', InputOption::VALUE_REQUIRED, 'Directory to write generated files', '/tmp/dns-zones')
            ->addOption('deploy', null, InputOption::VALUE_NONE, 'Deploy to configured DNS servers after generating');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $outputDir = $input->getOption('output-dir');
        $deploy    = $input->getOption('deploy');

        $servers = $this->serverRepo->findBy([], ['name' => 'ASC']);

        if (empty($servers)) {
            $io->warning('No DNS servers configured.');
            return Command::SUCCESS;
        }

        $failed = false;

        foreach ($servers as $server) {
            $io->section($server->getName() . ' (' . $server->getHostname() . ')');

            $serverDir = $outputDir . '/' . $server->getName();
            if (!is_dir($serverDir) && !mkdir($serverDir, 0755, true) && !is_dir($serverDir)) {
                $io->error("Cannot create output directory: $serverDir");
                return Command::FAILURE;
            }

            foreach ($server->getViews() as $view) {
                $viewDir = $serverDir . '/' . $view->getName();
                if (!is_dir($viewDir) && !mkdir($viewDir, 0755, true) && !is_dir($viewDir)) {
                    $io->error("Cannot create view directory: $viewDir");
                    return Command::FAILURE;
                }

                foreach ($this->generator->domainsForView($view) as $domain) {
                    $filename = $viewDir . '/' . $domain->getName() . '.zone';
                    file_put_contents($filename, $this->generator->generateZoneFile($domain, $view));
                    $io->writeln(' <info>Wrote</info> ' . $filename);
                }

                foreach ($this->generator->subnetsForView($view) as $subnet) {
                    foreach (array_filter([$subnet->getIpv4Cidr(), $subnet->getIpv6Cidr()]) as $cidr) {
                        $zoneName = $this->generator->reverseZoneName($cidr);
                        $filename = $viewDir . '/' . $zoneName . '.zone';
                        file_put_contents($filename, $this->generator->generateReverseZoneFile($subnet, $cidr, $view));
                        $io->writeln(' <info>Wrote</info> ' . $filename);
                    }
                }
            }

            // Write dashddi.conf
            $confFile = $serverDir . '/dashddi.conf';
            file_put_contents($confFile, $this->generator->generateViewsConf($server));
            $io->writeln(' <info>Wrote</info> ' . $confFile);

            if ($deploy) {
                $startedAt = new \DateTimeImmutable();
                try {
                    $results   = $this->deployer->deployToServer($server);
                    $success   = $this->isSuccess($results);
                    $error     = null;
                } catch (\Throwable $e) {
                    $results = [];
                    $success = false;
                    $error   = $e->getMessage();
                    $io->error($e->getMessage());
                    $failed = true;
                }

                $this->em->persist(new PushLog('dns', $server->getName(), $success, $results, $startedAt, new \DateTimeImmutable(), $error));
                $this->em->flush();

                foreach ($results['views'] ?? [] as $viewName => $viewResult) {
                    if (!$viewResult['mkdir']['success']) {
                        $io->writeln(' <error>mkdir failed</error> ' . $viewName . ': ' . $viewResult['mkdir']['output']);
                        $failed = true;
                    }
                    foreach ($viewResult['zones'] as $domainName => $zone) {
                        if ($zone['success']) {
                            $io->writeln(' <info>Deployed</info> ' . $zone['file']);
                        } else {
                            $io->writeln(' <error>Failed</error>  ' . $zone['file'] . ': ' . $zone['output']);
                            $failed = true;
                        }
                    }
                }

                if (($results['conf'] ?? null) !== null) {
                    if ($results['conf']['success']) {
                        $io->writeln(' <info>Deployed</info> dashddi.conf');
                    } else {
                        $io->writeln(' <error>Failed</error>  dashddi.conf: ' . $results['conf']['output']);
                        $failed = true;
                    }
                }

                if (($results['reload'] ?? null) !== null) {
                    if ($results['reload']['success']) {
                        $io->writeln(' <info>rndc reload</info> OK');
                    } else {
                        $io->writeln(' <error>rndc reload failed</error>: ' . $results['reload']['output']);
                        $failed = true;
                    }
                }
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    private function isSuccess(array $result): bool
    {
        foreach ($result['views'] ?? [] as $viewResult) {
            if (isset($viewResult['mkdir']) && !$viewResult['mkdir']['success']) {
                return false;
            }
            foreach ($viewResult['zones'] ?? [] as $zone) {
                if (!$zone['success']) {
                    return false;
                }
            }
        }
        if (isset($result['conf']) && !$result['conf']['success']) {
            return false;
        }
        if (isset($result['reload']) && !$result['reload']['success']) {
            return false;
        }
        return true;
    }
}
