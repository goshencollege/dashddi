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
        $results    = [];

        $content    = $this->generator->generateClientsConf();
        $remoteFile = $remotePath . '/clients.conf';
        $ok         = $sftp->put($remoteFile, $content);

        $results['clients.conf'] = [
            'success' => $ok,
            'file'    => 'clients.conf',
            'output'  => $ok ? '' : 'SFTP upload failed',
            'reload'  => null,
        ];

        if (!$ok) {
            return $results;
        }

        $reloadOut = $sftp->exec('systemctl reload freeradius 2>&1 || kill -HUP $(cat /var/run/freeradius/freeradius.pid 2>/dev/null) 2>&1');
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
