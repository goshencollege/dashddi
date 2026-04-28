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

    public function scpFile(string $localFile, string $target, ?string $sshKey, ?string &$output): int
    {
        $keyFlag = $sshKey ? '-i ' . escapeshellarg($sshKey) . ' ' : '';
        $cmd = sprintf(
            'scp -o BatchMode=yes -o StrictHostKeyChecking=accept-new %s%s %s 2>&1',
            $keyFlag,
            escapeshellarg($localFile),
            escapeshellarg($target),
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

    public function deployToServer(DhcpServer $server): array
    {
        $files = $this->generateFiles('/tmp/kea');
        $results = [];

        foreach ($files as $type => $localFile) {
            $target = sprintf(
                '%s@%s:%s/%s',
                $server->getSshUser(),
                $server->getHostname(),
                rtrim($server->getRemotePath(), '/'),
                basename($localFile),
            );

            $exitCode = $this->scpFile($localFile, $target, $server->getSshKeyPath(), $scpOutput);
            $result = [
                'success' => $exitCode === 0,
                'output'  => $scpOutput,
                'file'    => basename($localFile),
                'reload'  => null,
            ];

            if ($result['success'] && $server->getControlUrl()) {
                $service = $type === 'dhcp4' ? 'dhcp4' : 'dhcp6';
                $result['reload'] = $this->reloadKea($server, $service);
            }

            $results[$type] = $result;
        }

        return $results;
    }

    private function reloadKea(DhcpServer $server, string $service): array
    {
        $url     = rtrim($server->getControlUrl(), '/');
        $payload = json_encode(['command' => 'config-reload', 'service' => [$service]]);

        $headers = "Content-Type: application/json\r\nContent-Length: " . strlen($payload);
        if ($server->getControlUser() !== null) {
            $credentials = base64_encode($server->getControlUser() . ':' . ($server->getControlPassword() ?? ''));
            $headers .= "\r\nAuthorization: Basic $credentials";
        }

        $context  = stream_context_create([
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

        $data   = json_decode($body, true);
        $result = $data[0]['result'] ?? -1;

        return [
            'success'  => $result === 0,
            'response' => $data[0]['text'] ?? $body,
        ];
    }
}
