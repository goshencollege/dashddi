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

        $results['clients.conf'] = $this->deployFile(
            $sftp,
            $this->generator->generateClientsConf(),
            '/tmp/dashddi_clients.conf',
            $remotePath . '/clients.conf',
            'clients.conf',
        );

        $results['authorize'] = $this->deployFile(
            $sftp,
            $this->generator->generateAuthorizeFile(),
            '/tmp/dashddi_authorize',
            $remotePath . '/mods-config/files/authorize',
            'mods-config/files/authorize',
        );

        // Reload once after all files are in place
        if ($results['clients.conf']['success'] && $results['authorize']['success']) {
            $reloadOut = $sftp->exec('sudo systemctl reload freeradius 2>&1');
            $results['authorize']['reload'] = [
                'success' => $sftp->getExitStatus() === 0,
                'output'  => trim((string) $reloadOut),
            ];
        }

        return $results;
    }

    private function deployFile(
        \phpseclib3\Net\SFTP $sftp,
        string $content,
        string $tmpPath,
        string $destPath,
        string $label,
    ): array {
        $result = ['success' => false, 'file' => $label, 'output' => '', 'reload' => null];

        if (!$sftp->put($tmpPath, $content)) {
            $result['output'] = 'SFTP upload to /tmp failed';
            return $result;
        }

        $out = $sftp->exec('sudo cp ' . escapeshellarg($tmpPath) . ' ' . escapeshellarg($destPath) . ' 2>&1');
        if ($sftp->getExitStatus() !== 0) {
            $result['output'] = 'sudo cp failed: ' . trim((string) $out);
            return $result;
        }

        $result['success'] = true;
        return $result;
    }

    private function getSftp(RadiusServer $server): \phpseclib3\Net\SFTP
    {
        if (!$server->getSshPrivateKey()) {
            throw new \RuntimeException('No SSH key configured for "' . $server->getName() . '". Generate one by editing the server.');
        }

        return $this->sshKeys->connect($server->getHostname(), $server->getSshUser(), $server->getSshPrivateKey());
    }
}
