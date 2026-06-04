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
        $hasDomains = false;

        $isSecondary = $server->isSecondary();

        foreach ($server->getViews() as $view) {
            $viewName = $view->getName();
            $domains  = $this->generator->domainsForView($view);
            $subnets  = $this->generator->subnetsForView($view);

            $viewResult = ['mkdir' => null, 'zones' => []];

            $mkdirOut = $sftp->exec('mkdir -p ' . escapeshellarg($zonePath . '/' . $viewName));
            $viewResult['mkdir'] = ['success' => $sftp->getExitStatus() === 0, 'output' => trim((string) $mkdirOut)];

            if (!$isSecondary) {
                foreach ($domains as $domain) {
                    $hasDomains = true;
                    if ($keyDirBase && $domain->getDnssecPolicy()) {
                        $sftp->exec('mkdir -p ' . escapeshellarg($keyDirBase . '/' . $domain->getName()));
                    }
                    $remotePath  = $zonePath . '/' . $viewName . '/' . $domain->getName() . '.zone';
                    $displayFile = $viewName . '/' . $domain->getName() . '.zone';
                    $ok = $sftp->put($remotePath, $this->generator->generateZoneFile($domain, $view));
                    $viewResult['zones'][$domain->getName()] = [
                        'success' => $ok,
                        'file'    => $displayFile,
                        'output'  => $ok ? '' : 'SFTP upload failed',
                    ];
                }

                foreach ($subnets as $subnet) {
                    foreach (array_filter([$subnet->getIpv4Cidr(), $subnet->getIpv6Cidr()]) as $cidr) {
                        $hasDomains  = true;
                        $zoneName    = $this->generator->reverseZoneName($cidr);
                        if ($keyDirBase && $subnet->getDnssecPolicy()) {
                            $sftp->exec('mkdir -p ' . escapeshellarg($keyDirBase . '/' . $zoneName));
                        }
                        $remotePath  = $zonePath . '/' . $viewName . '/' . $zoneName . '.zone';
                        $displayFile = $viewName . '/' . $zoneName . '.zone';
                        $ok = $sftp->put($remotePath, $this->generator->generateReverseZoneFile($subnet, $cidr, $view));
                        $viewResult['zones'][$zoneName] = [
                            'success' => $ok,
                            'file'    => $displayFile,
                            'output'  => $ok ? '' : 'SFTP upload failed',
                        ];
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

    private function getSftp(DnsServer $server): SFTP
    {
        if (!$server->getSshPrivateKey()) {
            throw new \RuntimeException('No SSH key configured for "' . $server->getName() . '". Generate one by editing the server.');
        }

        return $this->sshKeys->connect($server->getHostname(), $server->getSshUser(), $server->getSshPrivateKey());
    }
}
