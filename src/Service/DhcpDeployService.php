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

        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $this->writeJsonFile($files['global4'], $this->generator->generateGlobalReservations4Config(), $flags);
        $this->writeJsonFile($files['global6'], $this->generator->generateGlobalReservations6Config(), $flags);
        $this->writeJsonFile($files['dhcp4'], $this->generator->generateDhcp4Config(), $flags);
        $this->writeJsonFile($files['dhcp6'], $this->generator->generateDhcp6Config(), $flags);

        return $files;
    }

    /**
     * Encodes and validates before writing, so a value that can't be encoded (e.g. invalid
     * UTF-8) fails loudly here instead of silently producing a config a DHCP server then rejects.
     */
    private function writeJsonFile(string $path, array $data, int $flags): void
    {
        $json = json_encode($data, $flags);
        if ($json === false) {
            throw new \RuntimeException(sprintf('Failed to encode %s: %s', basename($path), json_last_error_msg()));
        }
        file_put_contents($path, $json . "\n");
    }

    public function deployToServer(DhcpServer $server, array $allFiles, bool $reload = true): array
    {
        $sftp     = $this->getSftp($server);
        $scope    = $server->getVersionScope();
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
            $ok = $this->uploadAtomic($sftp, $remotePath, (string) file_get_contents($localFile));
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
            $ok = $this->uploadAtomic($sftp, $remotePath, (string) file_get_contents($localFile));
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
                $restored = $this->uploadAtomic($sftp, $remotePath, $backupContent);
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
        $this->writeJsonFile($localFile, $this->ddnsGenerator->generateConfig(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $remotePath = rtrim($server->getRemotePath(), '/') . '/kea-dhcp-ddns.conf';

        $ok     = $this->uploadAtomic($sftp, $remotePath, (string) file_get_contents($localFile));
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

    /**
     * Writes to a temp remote path and atomically renames it into place, so a reload
     * triggered right after — by us or by anything else watching the file on the server —
     * can never observe a partially-written config.
     */
    private function uploadAtomic(SFTP $sftp, string $remotePath, string $contents): bool
    {
        $tmpPath = $remotePath . '.tmp';

        return $sftp->put($tmpPath, $contents) && $sftp->posix_rename($tmpPath, $remotePath);
    }

    private function getSftp(DhcpServer $server): SFTP
    {
        if (!$server->getSshPrivateKey()) {
            throw new \RuntimeException('No SSH key configured for "' . $server->getName() . '". Generate one by editing the server.');
        }

        return $this->sshKeys->connect($server->getHostname(), $server->getSshUser(), $server->getSshPrivateKey());
    }

    /**
     * Send a lease4-del/lease6-del command for a single IP to free it immediately,
     * instead of waiting for Kea's own lease lifetime to expire it. Requires the
     * server's Kea config to have the lease_cmds hook loaded.
     *
     * Checks before and after the delete:
     * - If no lease exists at the address, there's nothing to do.
     * - If $expectedMac is given and a lease exists under a DIFFERENT hw-address,
     *   the address is left alone rather than deleted — lease{4,6}-del matches
     *   purely by IP, so blindly deleting here could kick an unrelated device off a
     *   lease it currently holds (e.g. the address was reassigned after this
     *   interface last used it, as can happen with a stale lastDhcpIp).
     * - After deleting, the lease is re-queried to confirm it actually took effect.
     *   Kea's own result code for lease4-del/lease6-del is not sufficient proof on
     *   its own — a still-present lease here is reported as a failure even though
     *   the delete command itself reported success.
     */
    public function deleteLease(DhcpServer $server, string $service, string $ipAddress, ?string $expectedMac = null): array
    {
        $sftp      = $this->getSftp($server);
        $deleteCmd = $service === 'dhcp4' ? 'lease4-del' : 'lease6-del';
        $getCmd    = $service === 'dhcp4' ? 'lease4-get' : 'lease6-get';
        $arguments = ['ip-address' => $ipAddress];

        $before = $this->controlCommand($getCmd, $service, $server, $sftp, $arguments);

        if ($before['resultCode'] === 3) {
            return ['ip' => $ipAddress, 'success' => true, 'response' => 'No lease found — nothing to delete.'];
        }

        if ($expectedMac !== null && $before['resultCode'] === 0) {
            $leaseMac = $before['data']['hw-address'] ?? null;
            if ($leaseMac !== null && strcasecmp($leaseMac, $expectedMac) !== 0) {
                return [
                    'ip'       => $ipAddress,
                    'success'  => true,
                    'response' => "Left alone — currently leased to a different device ($leaseMac).",
                ];
            }
        }

        $delete = $this->controlCommand($deleteCmd, $service, $server, $sftp, $arguments);
        $verify = $this->controlCommand($getCmd, $service, $server, $sftp, $arguments);

        // lease{4,6}-get result 0 = lease found, 3 = none found. If it's still found
        // after a delete that itself reported success, the delete didn't really work.
        $stillPresent = $verify['resultCode'] === 0;

        return [
            'ip'       => $ipAddress,
            'success'  => $delete['success'] && !$stillPresent,
            'response' => $stillPresent
                ? $delete['response'] . ' (still present after delete — verification failed)'
                : $delete['response'],
        ];
    }

    private function controlCommand(string $command, string $service, DhcpServer $server, SFTP $sftp, array $arguments = []): array
    {
        $port = $server->getControlPort() ?? 8000;
        $body = ['command' => $command, 'service' => [$service]];
        if (!empty($arguments)) {
            $body['arguments'] = $arguments;
        }
        $payload = json_encode($body);

        $cmd = 'curl -s -X POST ' . escapeshellarg('http://127.0.0.1:' . $port)
             . ' -H ' . escapeshellarg('Content-Type: application/json')
             . ' -d ' . escapeshellarg($payload);

        $output = $sftp->exec($cmd);

        if ($output === false) {
            return ['success' => false, 'response' => 'SSH exec of curl failed', 'resultCode' => null, 'data' => []];
        }

        $data       = json_decode($output, true);
        $resultCode = $data[0]['result'] ?? -1;

        return [
            // result 3 = "empty" (no matching lease) — a normal outcome for lease
            // deletion, not a failure.
            'success'    => in_array($resultCode, [0, 3], true),
            'response'   => $data[0]['text'] ?? $output,
            'resultCode' => $resultCode,
            'data'       => $data[0]['arguments'] ?? [],
        ];
    }
}
