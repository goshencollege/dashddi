<?php

namespace App\Service;

use App\Entity\DhcpServer;
use phpseclib3\Net\SFTP;

class DhcpDeployService
{
    public function __construct(
        private readonly DhcpConfigGenerator $generator,
        private readonly SshKeyService $sshKeys,
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
        $sftp  = $this->getSftp($server);
        $files = $this->generateFiles(sys_get_temp_dir() . '/dhcp');
        $results = [];

        foreach ($files as $type => $localFile) {
            $remotePath = rtrim($server->getRemotePath(), '/') . '/' . basename($localFile);
            $backupContent = null;

            // Download current remote file as a backup before overwriting
            if ($reload && $server->getControlUrl()) {
                $downloaded = $sftp->get($remotePath);
                if ($downloaded !== false) {
                    $backupContent = $downloaded;
                }
            }

            // Upload new file
            $ok = $sftp->put($remotePath, (string) file_get_contents($localFile));
            $result = [
                'success' => $ok,
                'output'  => $ok ? '' : 'SFTP upload failed',
                'file'    => basename($localFile),
                'reload'  => null,
            ];

            if (!$result['success'] || !$reload || !$server->getControlUrl()) {
                $results[$type] = $result;
                continue;
            }

            // Reload — the server validates config before applying it, so a bad file will
            // fail here without affecting the running service.
            $keaService   = $type === 'dhcp4' ? 'dhcp4' : 'dhcp6';
            $reloadResult = $this->controlCommand('config-reload', $keaService, $server);
            $result['reload'] = [
                'success'  => $reloadResult['success'],
                'response' => $reloadResult['response'],
                'stage'    => 'config-reload',
                'restored' => false,
            ];

            if (!$reloadResult['success'] && $backupContent !== null) {
                $restored = $sftp->put($remotePath, $backupContent);
                if ($restored) {
                    $this->controlCommand('config-reload', $keaService, $server);
                }
                $result['reload']['restored']      = $restored;
                $result['reload']['restore_error'] = $restored ? null : 'SFTP restore failed';
            }

            $results[$type] = $result;
        }

        return $results;
    }

    public function reloadDhcp(string $controlUrl, string $service, ?string $user = null, ?string $password = null): array
    {
        return $this->controlRequest($controlUrl, 'config-reload', $service, $user, $password);
    }

    private function getSftp(DhcpServer $server): SFTP
    {
        if (!$server->getSshPrivateKey()) {
            throw new \RuntimeException('No SSH key configured for "' . $server->getName() . '". Generate one by editing the server.');
        }

        return $this->sshKeys->connect($server->getHostname(), $server->getSshUser(), $server->getSshPrivateKey());
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
            return ['success' => false, 'response' => 'Could not connect to DHCP Control Agent'];
        }

        $data = json_decode($body, true);

        // result 0 = success; result 3 = unsupported command (e.g. config-test not available)
        $resultCode = $data[0]['result'] ?? -1;

        return [
            'success'  => $resultCode === 0,
            'response' => $data[0]['text'] ?? $body,
        ];
    }
}
