<?php

namespace App\Service;

use App\Entity\DnsServer;

class DnsDeployService
{
    public function __construct(private readonly DnsConfigGenerator $generator) {}

    /**
     * For each view on the server:
     *   - Creates a remote subdirectory ({remoteZonePath}/{viewName}/)
     *   - Generates and SCPs a zone file per domain in that view
     * Then SCPs a views.conf and runs rndc reload.
     *
     * Result shape:
     * [
     *   'views'  => [ 'viewName' => [ 'mkdir' => [...], 'zones' => [ 'domain' => [...] ] ] ],
     *   'conf'   => [ 'success' => bool, 'file' => 'views.conf', 'output' => string ],
     *   'reload' => [ 'success' => bool, 'output' => string ] | null,
     * ]
     */
    public function deployToServer(DnsServer $server): array
    {
        $results   = ['views' => [], 'conf' => null, 'reload' => null];
        $zonePath  = rtrim($server->getRemoteZonePath(), '/');
        $hasDomains = false;

        foreach ($server->getViews() as $view) {
            $viewName = $view->getName();
            $domains  = $this->generator->domainsForView($view);
            $subnets  = $this->generator->subnetsForView($view);

            $viewResult = ['mkdir' => null, 'zones' => []];

            // Create the per-view subdirectory on the remote host
            $mkdirExit = $this->runSsh($server, 'mkdir -p ' . escapeshellarg($zonePath . '/' . $viewName), $mkdirOutput);
            $viewResult['mkdir'] = ['success' => $mkdirExit === 0, 'output' => $mkdirOutput];

            // Forward zone files
            foreach ($domains as $domain) {
                $hasDomains = true;
                $viewResult['zones'][$domain->getName()] = $this->scpZone(
                    $server,
                    $this->generator->generateZoneFile($domain, $view),
                    $viewName . '_' . $domain->getName(),
                    $zonePath . '/' . $viewName . '/' . $domain->getName() . '.zone',
                    $viewName . '/' . $domain->getName() . '.zone',
                );
            }

            // Reverse zone files
            foreach ($subnets as $subnet) {
                foreach (array_filter([$subnet->getIpv4Cidr(), $subnet->getIpv6Cidr()]) as $cidr) {
                    $hasDomains = true;
                    $zoneName   = $this->generator->reverseZoneName($cidr);
                    $viewResult['zones'][$zoneName] = $this->scpZone(
                        $server,
                        $this->generator->generateReverseZoneFile($subnet, $cidr, $view),
                        $viewName . '_rev_' . md5($cidr),
                        $zonePath . '/' . $viewName . '/' . $zoneName . '.zone',
                        $viewName . '/' . $zoneName . '.zone',
                    );
                }
            }

            $results['views'][$viewName] = $viewResult;
        }

        // Generate and deploy views.conf
        $confContent = $this->generator->generateViewsConf($server);
        $localConf   = sys_get_temp_dir() . '/ipam_dns_views.conf';
        file_put_contents($localConf, $confContent);

        $confTarget = sprintf('%s@%s:%s', $server->getSshUser(), $server->getHostname(), $zonePath . '/views.conf');
        $confExit   = $this->runScp($localConf, $confTarget, $server->getSshKeyPath(), $confOutput);
        @unlink($localConf);

        $results['conf'] = ['success' => $confExit === 0, 'file' => 'views.conf', 'output' => $confOutput];

        // rndc reload
        if ($hasDomains || $confExit === 0) {
            $rndcExit = $this->runSsh($server, 'rndc reload', $rndcOutput);
            $results['reload'] = ['success' => $rndcExit === 0, 'output' => $rndcOutput];
        }

        return $results;
    }

    private function scpZone(DnsServer $server, string $content, string $tmpSuffix, string $remotePath, string $displayFile): array
    {
        $localFile = sys_get_temp_dir() . '/ipam_dns_' . $tmpSuffix . '.zone';
        file_put_contents($localFile, $content);
        $target  = sprintf('%s@%s:%s', $server->getSshUser(), $server->getHostname(), $remotePath);
        $scpExit = $this->runScp($localFile, $target, $server->getSshKeyPath(), $scpOutput);
        @unlink($localFile);
        return ['success' => $scpExit === 0, 'file' => $displayFile, 'output' => $scpOutput];
    }

    private function runScp(string $source, string $dest, string $sshKeyPath, ?string &$output): int
    {
        $cmd = sprintf(
            'scp -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o UpdateHostKeys=no -o ConnectTimeout=10 -i %s %s %s 2>&1',
            escapeshellarg($sshKeyPath),
            escapeshellarg($source),
            escapeshellarg($dest),
        );

        return $this->exec($cmd, $output);
    }

    private function runSsh(DnsServer $server, string $remoteCmd, ?string &$output): int
    {
        $cmd = sprintf(
            'ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o UpdateHostKeys=no -o ConnectTimeout=10 -i %s %s@%s %s 2>&1',
            escapeshellarg($server->getSshKeyPath()),
            escapeshellarg($server->getSshUser()),
            escapeshellarg($server->getHostname()),
            escapeshellarg($remoteCmd),
        );

        return $this->exec($cmd, $output);
    }

    private function exec(string $cmd, ?string &$output): int
    {
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            $output = 'Failed to spawn process';
            return 1;
        }

        $output = trim(stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($proc);
    }
}
