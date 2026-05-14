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

        $results['sites-available/default'] = $this->deployFile(
            $sftp,
            $this->generator->generateDefaultSite(),
            '/tmp/dashddi_default_site',
            $remotePath . '/sites-available/default',
            'sites-available/default',
        );

        $results['mods-enabled']  = $this->syncEnabled($sftp, $remotePath . '/mods-enabled',  $remotePath . '/mods-available',  ['files', 'always']);
        $results['sites-enabled'] = $this->syncEnabled($sftp, $remotePath . '/sites-enabled', $remotePath . '/sites-available', ['default']);

        // Reload once after all files are in place
        if ($results['clients.conf']['success'] && $results['authorize']['success'] && $results['sites-available/default']['success'] && $results['mods-enabled']['success'] && $results['sites-enabled']['success']) {
            $reloadOut = $sftp->exec('sudo systemctl reload freeradius 2>&1');
            $results['reload'] = [
                'success' => $sftp->getExitStatus() === 0,
                'output'  => trim((string) $reloadOut),
            ];
        }

        return $results;
    }

    private function syncEnabled(\phpseclib3\Net\SFTP $sftp, string $enabledDir, string $availableDir, array $names): array
    {
        $label = basename($enabledDir);

        $out = $sftp->exec('sudo /usr/bin/find ' . escapeshellarg($enabledDir) . ' -maxdepth 1 -type l -delete 2>&1');
        if ($sftp->getExitStatus() !== 0) {
            return ['success' => false, 'file' => $label, 'output' => 'clear failed: ' . trim((string) $out)];
        }

        foreach ($names as $name) {
            $target = $availableDir . '/' . $name;
            $link   = $enabledDir . '/' . $name;
            $out    = $sftp->exec('sudo /bin/ln -sf ' . escapeshellarg($target) . ' ' . escapeshellarg($link) . ' 2>&1');
            if ($sftp->getExitStatus() !== 0) {
                return ['success' => false, 'file' => $label, 'output' => 'enable ' . $name . ' failed: ' . trim((string) $out)];
            }
        }

        return ['success' => true, 'file' => $label, 'output' => ''];
    }

    private function deployFile(
        \phpseclib3\Net\SFTP $sftp,
        string $content,
        string $tmpPath,
        string $destPath,
        string $label,
    ): array {
        $result = ['success' => false, 'file' => $label, 'output' => ''];

        if (!$sftp->put($tmpPath, $content)) {
            $result['output'] = 'SFTP upload to /tmp failed';
            return $result;
        }

        $out = $sftp->exec('sudo /bin/cp ' . escapeshellarg($tmpPath) . ' ' . escapeshellarg($destPath) . ' 2>&1');
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
