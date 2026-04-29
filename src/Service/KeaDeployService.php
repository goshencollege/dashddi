<?php

namespace App\Service;

use App\Entity\DhcpServer;

class KeaDeployService
{
    public function __construct(
        private readonly KeaConfigGenerator $generator,
    ) {}

    public function generateFiles(string $outputDir): array
    {
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
            throw new \RuntimeException("Cannot create output directory: $outputDir");
        }

        $files = [
            'dhcp4' => $outputDir . '/subnets4.json',
            'dhcp6' => $outputDir . '/subnets6.json',
        ];

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
        $files = $this->generateFiles('/tmp/kea');
        $results = [];

        foreach ($files as $type => $localFile) {
            $keaService = $type === 'dhcp4' ? 'dhcp4' : 'dhcp6';
            $remotePath = rtrim($server->getRemotePath(), '/') . '/' . basename($localFile);
            $remoteTarget = sprintf('%s@%s:%s', $server->getSshUser(), $server->getHostname(), $remotePath);
            $backupFile = $localFile . '.bak';

            // Download current remote file as a backup before overwriting
            $hasBackup = false;
            if ($reload && $server->getControlUrl()) {
                $hasBackup = $this->runScp($remoteTarget, $backupFile, $server->getSshKeyPath(), $dlOutput) === 0;
            }

            // Upload new file
            $exitCode = $this->runScp($localFile, $remoteTarget, $server->getSshKeyPath(), $scpOutput);
            $result = [
                'success'  => $exitCode === 0,
                'output'   => $scpOutput,
                'file'     => basename($localFile),
                'reload'   => null,
            ];

            if (!$result['success'] || !$reload || !$server->getControlUrl()) {
                $results[$type] = $result;
                continue;
            }

            // Reload — Kea validates the config before applying it, so a bad file will
            // fail here without affecting the running service.
            $reloadResult = $this->controlCommand('config-reload', $keaService, $server);
            $result['reload'] = [
                'success'  => $reloadResult['success'],
                'response' => $reloadResult['response'],
                'stage'    => 'config-reload',
                'restored' => false,
            ];

            if (!$reloadResult['success'] && $hasBackup) {
                $restored = $this->runScp($backupFile, $remoteTarget, $server->getSshKeyPath(), $restoreOutput) === 0;
                if ($restored) {
                    $this->controlCommand('config-reload', $keaService, $server);
                }
                $result['reload']['restored']      = $restored;
                $result['reload']['restore_error'] = $restored ? null : $restoreOutput;
            }

            $results[$type] = $result;
        }

        return $results;
    }

    public function scpFile(string $localFile, string $target, ?string $sshKey, ?string &$output): int
    {
        return $this->runScp($localFile, $target, $sshKey, $output);
    }

    public function reloadKea(string $controlUrl, string $service, ?string $user = null, ?string $password = null): array
    {
        return $this->controlRequest($controlUrl, 'config-reload', $service, $user, $password);
    }

    private function controlCommand(string $command, string $service, DhcpServer $server): array
    {
        return $this->controlRequest(
            $server->getControlUrl(),
            $command,
            $service,
            $server->getControlUser(),
            $server->getControlPassword(),
        );
    }

    private function controlRequest(string $controlUrl, string $command, string $service, ?string $user, ?string $password): array
    {
        $url     = rtrim($controlUrl, '/');
        $payload = json_encode(['command' => $command, 'service' => [$service]]);

        $headers = "Content-Type: application/json\r\nContent-Length: " . strlen($payload);
        if ($user !== null) {
            $headers .= "\r\nAuthorization: Basic " . base64_encode($user . ':' . ($password ?? ''));
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => $headers,
                'content'       => $payload,
                'timeout'       => 10,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return ['success' => false, 'response' => 'Could not connect to Kea Control Agent'];
        }

        $data = json_decode($body, true);

        // result 0 = success; result 3 = unsupported command (e.g. config-test not available)
        $resultCode = $data[0]['result'] ?? -1;

        return [
            'success'  => $resultCode === 0,
            'response' => $data[0]['text'] ?? $body,
        ];
    }

    private function runScp(string $source, string $dest, ?string $sshKey, ?string &$output): int
    {
        $keyFlag = $sshKey ? '-i ' . escapeshellarg($sshKey) . ' ' : '';
        $cmd = sprintf(
            'scp -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o UpdateHostKeys=no -o ConnectTimeout=10 %s%s %s 2>&1',
            $keyFlag,
            escapeshellarg($source),
            escapeshellarg($dest),
        );

        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            $output = 'Failed to spawn scp process';
            return 1;
        }

        $output = trim(stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($proc);
    }
}
