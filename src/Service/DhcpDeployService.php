<?php

namespace App\Service;

use App\Entity\DhcpServer;
use phpseclib3\Net\SFTP;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DhcpDeployService
{
    public function __construct(
        private readonly DhcpConfigGenerator     $generator,
        private readonly DhcpDdnsConfigGenerator $ddnsGenerator,
        private readonly SshKeyService           $sshKeys,
        private readonly HttpClientInterface     $httpClient,
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
        $scope = $server->getVersionScope();
        $files = array_filter(
            $this->generateFiles(sys_get_temp_dir() . '/dhcp'),
            fn($type) => match ($scope) {
                'v4'    => $type === 'dhcp4',
                'v6'    => $type === 'dhcp6',
                default => true,
            },
            ARRAY_FILTER_USE_KEY,
        );
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
                'output'  => $ok ? '' : 'SFTP upload failed: ' . $sftp->getLastSFTPError(),
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

        if (!$result['success'] || !$reload || !$server->getControlUrl()) {
            return $result;
        }

        $reloadResult     = $this->controlCommand('config-reload', 'd2', $server);
        $result['reload'] = [
            'success'  => $reloadResult['success'],
            'response' => $reloadResult['response'],
            'stage'    => 'config-reload',
            'restored' => false,
        ];

        return $result;
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
        $url = rtrim($controlUrl, '/');

        $options = [
            'json'    => ['command' => $command, 'service' => [$service]],
            'timeout' => 10,
        ];
        if ($user !== null) {
            $options['auth_basic'] = [$user, $password ?? ''];
        }

        try {
            $response = $this->httpClient->request('POST', $url, $options);
            $body     = $response->getContent(false);
        } catch (\Throwable) {
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
