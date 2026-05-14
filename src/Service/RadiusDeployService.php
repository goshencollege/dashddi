<?php

namespace App\Service;

use App\Entity\RadiusServer;

class RadiusDeployService
{
    public function __construct(
        private readonly RadiusConfigGenerator $generator,
        private readonly SshKeyService $sshKeys,
    ) {}

    public function deployToServer(RadiusServer $server): array
    {
        $sftp       = $this->getSftp($server);
        $remotePath = rtrim($server->getRemotePath(), '/');
        $tmpFile    = '/tmp/dashddi_clients.conf';
        $destFile   = $remotePath . '/clients.conf';
        $results    = [];

        // SFTP into /tmp (no elevated permissions needed), then sudo cp into place
        $ok = $sftp->put($tmpFile, $this->generator->generateClientsConf());

        $results['clients.conf'] = [
            'success' => $ok,
            'file'    => 'clients.conf',
            'output'  => $ok ? '' : 'SFTP upload to /tmp failed',
            'reload'  => null,
        ];

        if (!$ok) {
            return $results;
        }

        $copyOut = $sftp->exec('sudo cp ' . escapeshellarg($tmpFile) . ' ' . escapeshellarg($destFile) . ' 2>&1');
        if ($sftp->getExitStatus() !== 0) {
            $results['clients.conf']['success'] = false;
            $results['clients.conf']['output']  = 'sudo cp failed: ' . trim((string) $copyOut);
            return $results;
        }

        $reloadOut = $sftp->exec('sudo systemctl reload freeradius 2>&1');
        $results['clients.conf']['reload'] = [
            'success' => $sftp->getExitStatus() === 0,
            'output'  => trim((string) $reloadOut),
        ];

        return $results;
    }

    private function getSftp(RadiusServer $server): \phpseclib3\Net\SFTP
    {
        if (!$server->getSshPrivateKey()) {
            throw new \RuntimeException('No SSH key configured for "' . $server->getName() . '". Generate one by editing the server.');
        }

        return $this->sshKeys->connect($server->getHostname(), $server->getSshUser(), $server->getSshPrivateKey());
    }
}
