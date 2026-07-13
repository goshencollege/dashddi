<?php

namespace App\Service;

use App\Entity\DhcpServer;
use phpseclib3\Net\SFTP;

class DhcpDeployService
{
    public function __construct(
        private readonly DhcpConfigGenerator     $generator,
        private readonly DhcpDdnsConfigGenerator $ddnsGenerator,
        private readonly SshKeyService           $sshKeys,
    ) {}

    public function generateFiles(string $outputDir): array
    {
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
            throw new \RuntimeException("Cannot create output directory: $outputDir");
        }

        $files = [
            'global4' => $outputDir . '/global-reservations4.json',
            'global6' => $outputDir . '/global-reservations6.json',
            'dhcp4'   => $outputDir . '/subnets4.json',
            'dhcp6'   => $outputDir . '/subnets6.json',
        ];

        file_put_contents(
            $files['global4'],
            json_encode($this->generator->generateGlobalReservations4Config(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );
        file_put_contents(
            $files['global6'],
            json_encode($this->generator->generateGlobalReservations6Config(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );
        file_put_contents(
            $files['dhcp4'],
            json_encode($this->generator->generateDhcp4Config(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );
        file_put_contents(
            $files['dhcp6'],
            json_encode($this->generator->generateDhcp6Config(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );

        return $files;
    }

    public function deployToServer(DhcpServer $server, bool $reload = true): array
    {
        $sftp     = $this->getSftp($server);
        $scope    = $server->getVersionScope();
        $allFiles = $this->generateFiles(sys_get_temp_dir() . '/dhcp');
        $results  = [];

        // Upload global reservation files first — no reload needed, the subnet reload below covers them
        $globalTypes = match ($scope) {
            'v4'    => ['global4'],
            'v6'    => ['global6'],
            default => ['global4', 'global6'],
        };
        foreach ($globalTypes as $type) {
            $localFile  = $allFiles[$type];
            $remotePath = rtrim($server->getRemotePath(), '/') . '/' . basename($localFile);
            $ok = $sftp->put($remotePath, (string) file_get_contents($localFile));
            $results[$type] = [
                'success' => $ok,
                'output'  => $ok ? '' : 'SFTP upload failed: ' . $sftp->getLastSFTPError(),
                'file'    => basename($localFile),
                'reload'  => null,
            ];
        }

        $files = array_filter(
            $allFiles,
            fn($type) => match ($scope) {
                'v4'    => $type === 'dhcp4',
                'v6'    => $type === 'dhcp6',
                default => in_array($type, ['dhcp4', 'dhcp6']),
            },
            ARRAY_FILTER_USE_KEY,
        );

        foreach ($files as $type => $localFile) {
            $remotePath = rtrim($server->getRemotePath(), '/') . '/' . basename($localFile);
            $backupContent = null;

            // Download current remote file as a backup before overwriting
            if ($reload && $server->getControlPort() !== null) {
                $downloaded = $sftp->get($remotePath);
                if ($downloaded !== false) {
                    $backupContent = $downloaded;
                }
            }

            // Upload new file
            $ok = $sftp->put($remotePath, (string) file_get_contents($localFile));
            $result = [
                'success' => $ok,
                'output'  => $ok ? '' : 'SFTP upload failed: ' . $sftp->getLastSFTPError(),
                'file'    => basename($localFile),
                'reload'  => null,
            ];

            if (!$result['success'] || !$reload || $server->getControlPort() === null) {
                $results[$type] = $result;
                continue;
            }

            // Reload — the server validates config before applying it, so a bad file will
            // fail here without affecting the running service.
            $keaService   = $type === 'dhcp4' ? 'dhcp4' : 'dhcp6';
            $reloadResult = $this->controlCommand('config-reload', $keaService, $server, $sftp);
            $result['reload'] = [
                'success'  => $reloadResult['success'],
                'response' => $reloadResult['response'],
                'stage'    => 'config-reload',
                'restored' => false,
            ];

            if (!$reloadResult['success'] && $backupContent !== null) {
                $restored = $sftp->put($remotePath, $backupContent);
                if ($restored) {
                    $this->controlCommand('config-reload', $keaService, $server, $sftp);
                }
                $result['reload']['restored']      = $restored;
                $result['reload']['restore_error'] = $restored ? null : 'SFTP restore failed';
            }

            $results[$type] = $result;
        }

        if ($server->isDdnsEnabled()) {
            $results['ddns'] = $this->deployDdnsConfig($sftp, $server, $reload);
        }

        return $results;
    }

    private function deployDdnsConfig(SFTP $sftp, DhcpServer $server, bool $reload): array
    {
        $tmpDir    = sys_get_temp_dir() . '/dhcp';
        $localFile = $tmpDir . '/kea-dhcp-ddns.conf';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }
        file_put_contents(
            $localFile,
            json_encode($this->ddnsGenerator->generateConfig(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        $remotePath = rtrim($server->getRemotePath(), '/') . '/kea-dhcp-ddns.conf';

        $ok     = $sftp->put($remotePath, (string) file_get_contents($localFile));
        $result = [
            'success' => $ok,
            'output'  => $ok ? '' : 'SFTP upload failed: ' . $sftp->getLastSFTPError(),
            'file'    => 'kea-dhcp-ddns.conf',
            'reload'  => null,
        ];

        if (!$result['success'] || !$reload || $server->getControlPort() === null) {
            return $result;
        }

        $reloadResult     = $this->controlCommand('config-reload', 'd2', $server, $sftp);
        $result['reload'] = [
            'success'  => $reloadResult['success'],
            'response' => $reloadResult['response'],
            'stage'    => 'config-reload',
            'restored' => false,
        ];

        return $result;
    }

    private function getSftp(DhcpServer $server): SFTP
    {
        if (!$server->getSshPrivateKey()) {
            throw new \RuntimeException('No SSH key configured for "' . $server->getName() . '". Generate one by editing the server.');
        }

        return $this->sshKeys->connect($server->getHostname(), $server->getSshUser(), $server->getSshPrivateKey());
    }

    private function controlCommand(string $command, string $service, DhcpServer $server, SFTP $sftp): array
    {
        $port    = $server->getControlPort() ?? 8000;
        $payload = json_encode(['command' => $command, 'service' => [$service]]);

        $cmd = 'curl -s -X POST ' . escapeshellarg('http://127.0.0.1:' . $port)
             . ' -H ' . escapeshellarg('Content-Type: application/json')
             . ' -d ' . escapeshellarg($payload);

        if ($server->getControlUser() !== null) {
            $cmd .= ' -u ' . escapeshellarg($server->getControlUser() . ':' . ($server->getControlPassword() ?? ''));
        }

        $output = $sftp->exec($cmd);

        if ($output === false) {
            return ['success' => false, 'response' => 'SSH exec of curl failed'];
        }

        $data       = json_decode($output, true);
        $resultCode = $data[0]['result'] ?? -1;

        return [
            'success'  => $resultCode === 0,
            'response' => $data[0]['text'] ?? $output,
        ];
    }
}
