<?php

namespace App\Service;

use App\Entity\DnsServer;
use phpseclib3\Net\SFTP;

class DnsDeployService
{
    public function __construct(
        private readonly DnsConfigGenerator $generator,
        private readonly SshKeyService $sshKeys,
    ) {}

    /**
     * For each view on the server:
     *   - Creates a remote subdirectory ({remoteZonePath}/{viewName}/)
     *   - Uploads a zone file per domain in that view via SFTP
     * Then uploads dashddi.conf and runs rndc reload.
     *
     * Result shape:
     * [
     *   'views'  => [ 'viewName' => [ 'mkdir' => [...], 'zones' => [ 'domain' => [...] ] ] ],
     *   'conf'   => [ 'success' => bool, 'file' => 'dashddi.conf', 'output' => string ],
     *   'reload' => [ 'success' => bool, 'output' => string ] | null,
     * ]
     */
    public function deployToServer(DnsServer $server): array
    {
        $sftp       = $this->getSftp($server);
        $results    = ['views' => [], 'conf' => null, 'reload' => null];
        $zonePath   = rtrim($server->getRemoteZonePath(), '/');
        $keyDirBase = $server->getKeyDirectory() ? rtrim($server->getKeyDirectory(), '/') : null;
        $bindUser   = $server->getBindUser();
        $hasDomains = false;

        $isSecondary = $server->isSecondary();

        foreach ($server->getViews() as $view) {
            $viewName = $view->getName();
            $domains  = $this->generator->domainsForView($view);
            $subnets  = $this->generator->subnetsForView($view);

            $viewResult = ['mkdir' => null, 'zones' => []];

            $mkdirOut = $sftp->exec('mkdir -p ' . escapeshellarg($zonePath . '/' . $viewName));
            $viewResult['mkdir'] = ['success' => $sftp->getExitStatus() === 0, 'output' => trim((string) $mkdirOut)];
            $sftp->exec('chown -R ' . escapeshellarg($bindUser . ':' . $bindUser) . ' ' . escapeshellarg($zonePath . '/' . $viewName));

            if (!$isSecondary) {
                foreach ($domains as $domain) {
                    $hasDomains = true;
                    if ($keyDirBase && $domain->getDnssecPolicy()) {
                        $dir = $keyDirBase . '/' . $domain->getName();
                        $sftp->exec('mkdir -p ' . escapeshellarg($dir));
                        $sftp->exec('chown ' . escapeshellarg($bindUser . ':' . $bindUser) . ' ' . escapeshellarg($dir));
                    }
                    $remotePath  = $zonePath . '/' . $viewName . '/' . $domain->getName() . '.zone';
                    $displayFile = $viewName . '/' . $domain->getName() . '.zone';
                    $isDynamic   = $domain->isDdnsEnabled()
                        && $domain->getDdnsDnsServer()?->getId() === $server->getId()
                        && $server->getDdnsAlgorithm();
                    if ($isDynamic && $sftp->file_exists($remotePath)) {
                        $nsu = $this->execNsUpdate($this->generator->generateDomainApexNsUpdate($domain, $view), $server, $sftp);
                        $viewResult['zones'][$domain->getName()] = [
                            'success' => $nsu['success'],
                            'file'    => $displayFile,
                            'output'  => $nsu['success'] ? 'SOA/NS updated via nsupdate' : ('nsupdate failed: ' . $nsu['output']),
                        ];
                    } else {
                        $ok = $sftp->put($remotePath, $this->generator->generateZoneFile($domain, $view));
                        $viewResult['zones'][$domain->getName()] = [
                            'success' => $ok,
                            'file'    => $displayFile,
                            'output'  => $ok ? '' : 'SFTP upload failed',
                        ];
                    }
                }

                foreach ($subnets as $subnet) {
                    foreach (array_filter([$subnet->getIpv4Cidr(), $subnet->getIpv6Cidr()]) as $cidr) {
                        $hasDomains = true;
                        try {
                            $zoneName = $this->generator->reverseZoneName($cidr);
                        } catch (\InvalidArgumentException $e) {
                            $viewResult['zones'][$cidr] = [
                                'success' => false,
                                'file'    => $cidr,
                                'output'  => 'Skipped: ' . $e->getMessage(),
                            ];
                            continue;
                        }
                        if ($keyDirBase && $subnet->getDnssecPolicy()) {
                            $dir = $keyDirBase . '/' . $zoneName;
                            $sftp->exec('mkdir -p ' . escapeshellarg($dir));
                            $sftp->exec('chown ' . escapeshellarg($bindUser . ':' . $bindUser) . ' ' . escapeshellarg($dir));
                        }
                        $remotePath  = $zonePath . '/' . $viewName . '/' . $zoneName . '.zone';
                        $displayFile = $viewName . '/' . $zoneName . '.zone';
                        $isDynamic   = $subnet->isDdnsEnabled()
                            && $subnet->getDdnsDnsServer()?->getId() === $server->getId()
                            && $server->getDdnsAlgorithm();
                        if ($isDynamic && $sftp->file_exists($remotePath)) {
                            $nsu = $this->execNsUpdate($this->generator->generateSubnetApexNsUpdate($subnet, $cidr, $view), $server, $sftp);
                            $viewResult['zones'][$zoneName] = [
                                'success' => $nsu['success'],
                                'file'    => $displayFile,
                                'output'  => $nsu['success'] ? 'SOA/NS updated via nsupdate' : ('nsupdate failed: ' . $nsu['output']),
                            ];
                        } else {
                            $ok = $sftp->put($remotePath, $this->generator->generateReverseZoneFile($subnet, $cidr, $view));
                            $viewResult['zones'][$zoneName] = [
                                'success' => $ok,
                                'file'    => $displayFile,
                                'output'  => $ok ? '' : 'SFTP upload failed',
                            ];
                        }
                    }
                }
            }

            $results['views'][$viewName] = $viewResult;
        }

        $confOk = $sftp->put($zonePath . '/dashddi.conf', $this->generator->generateViewsConf($server));
        $results['conf'] = ['success' => $confOk, 'file' => 'dashddi.conf', 'output' => $confOk ? '' : 'SFTP upload failed'];

        if ($hasDomains || $confOk) {
            $rndcOut = $sftp->exec('rndc reload');
            $results['reload'] = ['success' => $sftp->getExitStatus() === 0, 'output' => trim((string) $rndcOut)];
        }

        return $results;
    }

    private function execNsUpdate(string $script, DnsServer $server, SFTP $sftp): array
    {
        $tmpPath = '/tmp/.dashddi-nsu-' . bin2hex(random_bytes(6)) . '.txt';
        $sftp->put($tmpPath, $script);
        $yFlag  = $server->getDdnsAlgorithm()->bindName() . ':' . $server->getDdnsKeyName() . ':' . $server->getDdnsSecret();
        $output = $sftp->exec('nsupdate -y ' . escapeshellarg($yFlag) . ' ' . escapeshellarg($tmpPath) . '; rm -f ' . escapeshellarg($tmpPath));
        return ['success' => $sftp->getExitStatus() === 0, 'output' => trim((string) $output)];
    }

    private function getSftp(DnsServer $server): SFTP
    {
        if (!$server->getSshPrivateKey()) {
            throw new \RuntimeException('No SSH key configured for "' . $server->getName() . '". Generate one by editing the server.');
        }

        return $this->sshKeys->connect($server->getHostname(), $server->getSshUser(), $server->getSshPrivateKey());
    }
}
