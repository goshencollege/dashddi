<?php

namespace App\Service;

use App\Entity\ArubaSwitch;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

class ArubaCxService
{
    // ── REST helpers ──────────────────────────────────────────────────────────

    private function baseUrl(ArubaSwitch $creds, string $ip): string
    {
        return 'https://' . $ip . '/rest/' . $creds->getRestApiVersion();
    }

    /** @return array{success:bool, error:string, body:string, status:int, headers:string[]} */
    private function request(
        ArubaSwitch $creds,
        string      $ip,
        string      $method,
        string      $path,
        array       $headers = [],
        string      $body    = '',
        string      $cookie  = '',
    ): array {
        $url       = $this->baseUrl($creds, $ip) . $path;
        $headerStr = implode("\r\n", $headers) . "\r\n";
        if ($cookie !== '') {
            $headerStr .= 'Cookie: ' . $cookie . "\r\n";
        }

        $opts = [
            'method'        => $method,
            'header'        => $headerStr,
            'content'       => $body,
            'timeout'       => 10,
            'ignore_errors' => true,
        ];

        $ssl = $creds->isVerifyTls()
            ? []
            : ['verify_peer' => false, 'verify_peer_name' => false];

        $context  = stream_context_create(['http' => $opts, 'ssl' => $ssl]);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return ['success' => false, 'error' => 'Connection failed to ' . $ip, 'body' => '', 'status' => 0, 'headers' => []];
        }

        $status      = 0;
        $respHeaders = $http_response_header ?? [];
        foreach ($respHeaders as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
                $status = (int) $m[1];
            }
        }

        $ok = $status >= 200 && $status < 300;
        return [
            'success' => $ok,
            'error'   => $ok ? '' : 'HTTP ' . $status . ': ' . substr($response, 0, 200),
            'body'    => $response,
            'status'  => $status,
            'headers' => $respHeaders,
        ];
    }

    private function loginRest(ArubaSwitch $creds, string $ip): string
    {
        if ($creds->getPassword() === null) {
            throw new \RuntimeException('No password configured for REST API');
        }

        $body   = http_build_query(['username' => $creds->getUsername(), 'password' => $creds->getPassword()]);
        $result = $this->request($creds, $ip, 'POST', '/login', ['Content-Type: application/x-www-form-urlencoded'], $body);

        foreach ($result['headers'] as $h) {
            if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $h, $m)) {
                return trim($m[1]);
            }
        }

        if ($result['status'] === 0) {
            throw new \RuntimeException($result['error']);
        }

        throw new \RuntimeException('No session cookie returned by login endpoint (HTTP ' . $result['status'] . ')');
    }

    private function logoutRest(ArubaSwitch $creds, string $ip, string $cookie): void
    {
        $this->request($creds, $ip, 'POST', '/logout', [], '', $cookie);
    }

    /** Normalise a NAS-Port-ID string to just the interface name ("1/1/5 - ..." → "1/1/5"). */
    public static function normalisePortId(string $portId): string
    {
        return preg_replace('/[\s,]+.*$/', '', trim($portId)) ?? trim($portId);
    }

    private function encodePort(string $portId): string
    {
        return rawurlencode(self::normalisePortId($portId));
    }

    // ── SSH helpers ───────────────────────────────────────────────────────────

    private function sshConnect(ArubaSwitch $creds, string $ip): SSH2
    {
        $ssh = new SSH2($ip);
        $ssh->setTimeout(10);

        $loggedIn = false;
        if ($creds->getSshPrivateKey() !== null) {
            $loggedIn = $ssh->login($creds->getUsername(), PublicKeyLoader::load($creds->getSshPrivateKey()));
        }
        if (!$loggedIn && $creds->getPassword() !== null) {
            $loggedIn = $ssh->login($creds->getUsername(), $creds->getPassword());
        }
        if (!$loggedIn) {
            throw new \RuntimeException('SSH login failed for ' . $ip);
        }

        return $ssh;
    }

    // ── Port info ─────────────────────────────────────────────────────────────

    /**
     * Returns port-access client info for a switch port.
     * $ip is the switch management IP (taken from the ClearPass auth log nasIp).
     * Tries REST first; falls back to SSH.
     *
     * @return array{clients: list<array{mac:string,ip:?string,role:?string,status:?string,auth_method:?string}>, raw: string, via: string, error: ?string}
     */
    public function getPortInfo(ArubaSwitch $creds, string $ip, string $portId): array
    {
        $portId = self::normalisePortId($portId);

        if ($creds->getPassword() !== null) {
            try {
                return $this->getPortInfoRest($creds, $ip, $portId);
            } catch (\Throwable $e) {
                // Fall through to SSH
            }
        }

        if ($creds->getSshPrivateKey() !== null || $creds->getPassword() !== null) {
            try {
                return $this->getPortInfoSsh($creds, $ip, $portId);
            } catch (\Throwable $e) {
                return ['clients' => [], 'raw' => '', 'via' => 'ssh', 'error' => $e->getMessage()];
            }
        }

        return ['clients' => [], 'raw' => '', 'via' => 'none', 'error' => 'No credentials configured'];
    }

    private function getPortInfoRest(ArubaSwitch $creds, string $ip, string $portId): array
    {
        $cookie = $this->loginRest($creds, $ip);
        try {
            $path   = '/system/interfaces/' . $this->encodePort($portId) . '/port_access_clients?depth=2';
            $result = $this->request($creds, $ip, 'GET', $path, ['Accept: application/json'], '', $cookie);

            if (!$result['success']) {
                throw new \RuntimeException($result['error']);
            }

            $data    = json_decode($result['body'], true) ?? [];
            $clients = [];
            foreach ($data as $key => $entry) {
                if (!is_array($entry)) continue;
                $clients[] = [
                    'mac'         => $entry['mac'] ?? $key,
                    'vlan'        => (string) (array_key_first($entry['access_vlan'] ?? []) ?? ''),
                    'role'        => array_key_first($entry['applied_role'] ?? []) ?? null,
                    'status'      => $entry['client_state'] ?? null,
                    'auth_method' => $entry['onboarded_method'] ?? null,
                ];
            }

            return ['clients' => $clients, 'raw' => $result['body'], 'via' => 'rest', 'error' => null];
        } finally {
            $this->logoutRest($creds, $ip, $cookie);
        }
    }

    private function getPortInfoSsh(ArubaSwitch $creds, string $ip, string $portId): array
    {
        $ssh = $this->sshConnect($creds, $ip);
        $raw = (string) $ssh->exec('show port-access clients interface ' . $portId);

        return ['clients' => $this->parsePortAccessOutput($raw), 'raw' => $raw, 'via' => 'ssh', 'error' => null];
    }

    private function parsePortAccessOutput(string $output): array
    {
        $clients = [];
        $lines   = explode("\n", $output);

        // Detect table format: look for a line containing "MAC Address" as a header
        $headerLine = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/MAC\s+Address/i', $line)) {
                $headerLine = $i;
                break;
            }
        }

        if ($headerLine !== null) {
            $cols   = preg_split('/\s{2,}/', trim($lines[$headerLine]));
            $colMap = [];
            foreach ($cols as $ci => $col) {
                $col = strtolower(trim($col));
                if (str_contains($col, 'mac'))                                $colMap['mac']         = $ci;
                if (str_contains($col, 'ip'))                                 $colMap['ip']          = $ci;
                if (str_contains($col, 'role'))                               $colMap['role']        = $ci;
                if (str_contains($col, 'status') || str_contains($col, 'auth s')) $colMap['status'] = $ci;
                if (str_contains($col, 'method'))                             $colMap['auth_method'] = $ci;
            }

            for ($i = $headerLine + 1; $i < count($lines); $i++) {
                $line = trim($lines[$i]);
                if ($line === '' || preg_match('/^-+/', $line)) continue;
                $parts = preg_split('/\s{2,}/', $line);
                if (count($parts) < 2) continue;
                $clients[] = [
                    'mac'         => strtolower($parts[$colMap['mac'] ?? 0] ?? ''),
                    'ip'          => $parts[$colMap['ip'] ?? 1] ?? null,
                    'role'        => $parts[$colMap['role'] ?? 2] ?? null,
                    'status'      => $parts[$colMap['status'] ?? 3] ?? null,
                    'auth_method' => $parts[$colMap['auth_method'] ?? 4] ?? null,
                ];
            }
            return $clients;
        }

        // Key-value format ("Client MAC : xx:xx:xx:xx:xx:xx")
        $current = null;
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^Client\s+MAC\s*:\s*([0-9a-f:]+)/i', $line, $m)) {
                if ($current !== null) $clients[] = $current;
                $current = ['mac' => strtolower($m[1]), 'ip' => null, 'role' => null, 'status' => null, 'auth_method' => null];
            } elseif ($current !== null) {
                if (preg_match('/^Client\s+IP\s*:\s*(\S+)/i', $line, $m))  $current['ip']          = $m[1];
                if (preg_match('/^Client\s+Role\s*:\s*(.+)/i', $line, $m)) $current['role']        = trim($m[1]);
                if (preg_match('/^Auth\s+Status\s*:\s*(.+)/i', $line, $m)) $current['status']      = trim($m[1]);
                if (preg_match('/^Auth\s+Method\s*:\s*(.+)/i', $line, $m)) $current['auth_method'] = trim($m[1]);
            }
        }
        if ($current !== null) $clients[] = $current;

        return $clients;
    }

    // ── Port actions ──────────────────────────────────────────────────────────

    /** @return array{success: bool, error: ?string, output: ?string} */
    public function reauthenticatePort(ArubaSwitch $creds, string $ip, string $portId): array
    {
        set_time_limit(60);
        $portId = self::normalisePortId($portId);

        if ($creds->getSshPrivateKey() !== null || $creds->getPassword() !== null) {
            try {
                return $this->reauthenticatePortSsh($creds, $ip, $portId);
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => $e->getMessage(), 'output' => null];
            }
        }

        return ['success' => false, 'error' => 'No credentials configured', 'output' => null];
    }

    private function reauthenticatePortSsh(ArubaSwitch $creds, string $ip, string $portId): array
    {
        $cmd    = "port-access reauthenticate interface {$portId}";
        $ssh    = $this->sshConnect($creds, $ip);
        $output = trim((string) $ssh->exec($cmd));

        return ['success' => true, 'error' => null, 'output' => $this->formatSshLog([[$cmd, $output]])];
    }

    /**
     * Bounces a port (admin-down, 10 s pause, admin-up).
     * Tries REST first; falls back to SSH.
     *
     * @return array{success: bool, error: ?string}
     */
    public function bouncePort(ArubaSwitch $creds, string $ip, string $portId): array
    {
        set_time_limit(60);
        $portId = self::normalisePortId($portId);

        if ($creds->getPassword() !== null) {
            try {
                return $this->bouncePortRest($creds, $ip, $portId);
            } catch (\Throwable $e) {
                if ($creds->getSshPrivateKey() === null) {
                    return ['success' => false, 'error' => $e->getMessage()];
                }
            }
        }

        if ($creds->getSshPrivateKey() !== null || $creds->getPassword() !== null) {
            try {
                return $this->bouncePortSsh($creds, $ip, $portId);
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return ['success' => false, 'error' => 'No credentials configured'];
    }

    private function bouncePortRest(ArubaSwitch $creds, string $ip, string $portId): array
    {
        $cookie  = $this->loginRest($creds, $ip);
        $path    = '/system/interfaces/' . $this->encodePort($portId);
        $headers = ['Content-Type: application/json', 'Accept: application/json'];

        try {
            $down = $this->request($creds, $ip, 'PUT', $path, $headers, json_encode(['user_config' => ['admin' => 'down']]), $cookie);
            if (!$down['success']) {
                throw new \RuntimeException('Admin-down failed: ' . $down['error']);
            }

            sleep(10);

            $up = $this->request($creds, $ip, 'PUT', $path, $headers, json_encode(['user_config' => ['admin' => 'up']]), $cookie);
            if (!$up['success']) {
                throw new \RuntimeException('Admin-up failed: ' . $up['error']);
            }

            return ['success' => true, 'error' => null, 'output' => null];
        } finally {
            $this->logoutRest($creds, $ip, $cookie);
        }
    }

    private function bouncePortSsh(ArubaSwitch $creds, string $ip, string $portId): array
    {
        $cmd1 = "configure terminal\ninterface {$portId}\nshutdown\nend";
        $cmd2 = "configure terminal\ninterface {$portId}\nno shutdown\nend";
        $ssh  = $this->sshConnect($creds, $ip);
        $out1 = trim((string) $ssh->exec($cmd1));
        sleep(10);
        $out2 = trim((string) $ssh->exec($cmd2));

        return ['success' => true, 'error' => null, 'output' => $this->formatSshLog([[$cmd1, $out1], ['(10 s pause)', ''], [$cmd2, $out2]])];
    }

    /** @return array{success: bool, error: ?string} */
    public function poeBouncePort(ArubaSwitch $creds, string $ip, string $portId): array
    {
        set_time_limit(60);
        $portId = self::normalisePortId($portId);

        if ($creds->getSshPrivateKey() !== null || $creds->getPassword() !== null) {
            try {
                return $this->poeBouncePortSsh($creds, $ip, $portId);
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return ['success' => false, 'error' => 'No credentials configured'];
    }

    private function poeBouncePortSsh(ArubaSwitch $creds, string $ip, string $portId): array
    {
        $cmd1 = "configure terminal\ninterface {$portId}\nno power-over-ethernet\nshutdown\nend";
        $cmd2 = "configure terminal\ninterface {$portId}\nno shutdown\npower-over-ethernet\nend";
        $ssh  = $this->sshConnect($creds, $ip);
        $out1 = trim((string) $ssh->exec($cmd1));
        sleep(10);
        $out2 = trim((string) $ssh->exec($cmd2));

        return ['success' => true, 'error' => null, 'output' => $this->formatSshLog([[$cmd1, $out1], ['(10 s pause)', ''], [$cmd2, $out2]])];
    }

    /** Formats an array of [sent, received] pairs into a readable SSH log. */
    private function formatSshLog(array $steps): string
    {
        $lines = [];
        foreach ($steps as [$sent, $received]) {
            foreach (explode("\n", $sent) as $line) {
                $lines[] = '> ' . $line;
            }
            if ($received !== '') {
                $lines[] = $received;
            }
        }
        return implode("\n", $lines);
    }
}
