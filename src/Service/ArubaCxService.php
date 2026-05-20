<?php

namespace App\Service;

use App\Entity\ArubaSwitch;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

class ArubaCxService
{
    // ── REST helpers ──────────────────────────────────────────────────────────

    private function baseUrl(ArubaSwitch $switch): string
    {
        return 'https://' . $switch->getManagementIp() . '/rest/' . $switch->getRestApiVersion();
    }

    /** @return array{success:bool, error:string, body:string, status:int, headers:string[]} */
    private function request(
        ArubaSwitch $switch,
        string      $method,
        string      $path,
        array       $headers = [],
        string      $body    = '',
        string      $cookie  = '',
    ): array {
        $url       = $this->baseUrl($switch) . $path;
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

        $ssl = $switch->isVerifyTls()
            ? []
            : ['verify_peer' => false, 'verify_peer_name' => false];

        $context  = stream_context_create(['http' => $opts, 'ssl' => $ssl]);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return ['success' => false, 'error' => 'Connection failed to ' . $switch->getManagementIp(), 'body' => '', 'status' => 0, 'headers' => []];
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

    private function loginRest(ArubaSwitch $switch): string
    {
        if ($switch->getPassword() === null) {
            throw new \RuntimeException('No password configured for REST API');
        }

        $body   = http_build_query(['username' => $switch->getUsername(), 'password' => $switch->getPassword()]);
        $result = $this->request($switch, 'POST', '/login', ['Content-Type: application/x-www-form-urlencoded'], $body);

        if (!$result['success'] && $result['status'] !== 0) {
            // 200 or even 302 may come back — accept anything that gave us a cookie
        }

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

    private function logoutRest(ArubaSwitch $switch, string $cookie): void
    {
        $this->request($switch, 'POST', '/logout', [], '', $cookie);
    }

    /** Normalise a NAS-Port-ID string to just the interface name ("1/1/5 - ..." → "1/1/5"). */
    public static function normalisePortId(string $portId): string
    {
        // ClearPass sometimes appends description after a dash or comma
        $portId = preg_replace('/[\s,]+.*$/', '', trim($portId)) ?? trim($portId);
        return $portId;
    }

    private function encodePort(string $portId): string
    {
        return rawurlencode(self::normalisePortId($portId));
    }

    // ── SSH helpers ───────────────────────────────────────────────────────────

    private function sshConnect(ArubaSwitch $switch): SSH2
    {
        $ssh      = new SSH2($switch->getManagementIp());
        $ssh->setTimeout(10);

        $loggedIn = false;
        if ($switch->getSshPrivateKey() !== null) {
            $loggedIn = $ssh->login($switch->getUsername(), PublicKeyLoader::load($switch->getSshPrivateKey()));
        }
        if (!$loggedIn && $switch->getPassword() !== null) {
            $loggedIn = $ssh->login($switch->getUsername(), $switch->getPassword());
        }
        if (!$loggedIn) {
            throw new \RuntimeException('SSH login failed for ' . $switch->getManagementIp());
        }

        return $ssh;
    }

    // ── Port info ─────────────────────────────────────────────────────────────

    /**
     * Returns port-access client info for a switch port.
     * Tries REST first; falls back to SSH if no password or if REST fails.
     *
     * @return array{clients: list<array{mac:string,ip:?string,role:?string,status:?string,auth_method:?string}>, raw: string, via: string, error: ?string}
     */
    public function getPortInfo(ArubaSwitch $switch, string $portId): array
    {
        $portId = self::normalisePortId($portId);

        if ($switch->getPassword() !== null) {
            try {
                return $this->getPortInfoRest($switch, $portId);
            } catch (\Throwable $e) {
                // Fall through to SSH
            }
        }

        if ($switch->getSshPrivateKey() !== null || $switch->getPassword() !== null) {
            try {
                return $this->getPortInfoSsh($switch, $portId);
            } catch (\Throwable $e) {
                return ['clients' => [], 'raw' => '', 'via' => 'ssh', 'error' => $e->getMessage()];
            }
        }

        return ['clients' => [], 'raw' => '', 'via' => 'none', 'error' => 'No credentials configured'];
    }

    private function getPortInfoRest(ArubaSwitch $switch, string $portId): array
    {
        $cookie = $this->loginRest($switch);
        try {
            $path   = '/system/interfaces/' . $this->encodePort($portId) . '/port_access_clients?depth=2';
            $result = $this->request($switch, 'GET', $path, ['Accept: application/json'], '', $cookie);

            if (!$result['success']) {
                throw new \RuntimeException($result['error']);
            }

            $data    = json_decode($result['body'], true) ?? [];
            $clients = [];
            foreach ($data as $key => $entry) {
                if (!is_array($entry)) continue;
                $clients[] = [
                    'mac'         => $entry['mac_address'] ?? $key,
                    'ip'          => $entry['ip_address']  ?? null,
                    'role'        => $entry['role']         ?? null,
                    'status'      => $entry['session_state'] ?? $entry['auth_state'] ?? null,
                    'auth_method' => $entry['auth_method']  ?? null,
                ];
            }

            return ['clients' => $clients, 'raw' => $result['body'], 'via' => 'rest', 'error' => null];
        } finally {
            $this->logoutRest($switch, $cookie);
        }
    }

    private function getPortInfoSsh(ArubaSwitch $switch, string $portId): array
    {
        $ssh = $this->sshConnect($switch);
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
            // Table format — skip separator lines, parse data rows
            $cols = preg_split('/\s{2,}/', trim($lines[$headerLine]));
            $colMap = [];
            foreach ($cols as $ci => $col) {
                $col = strtolower(trim($col));
                if (str_contains($col, 'mac'))    $colMap['mac']         = $ci;
                if (str_contains($col, 'ip'))     $colMap['ip']          = $ci;
                if (str_contains($col, 'role'))   $colMap['role']        = $ci;
                if (str_contains($col, 'status') || str_contains($col, 'auth s')) $colMap['status'] = $ci;
                if (str_contains($col, 'method')) $colMap['auth_method'] = $ci;
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
                if (preg_match('/^Client\s+IP\s*:\s*(\S+)/i', $line, $m))     $current['ip'] = $m[1];
                if (preg_match('/^Client\s+Role\s*:\s*(.+)/i', $line, $m))    $current['role'] = trim($m[1]);
                if (preg_match('/^Auth\s+Status\s*:\s*(.+)/i', $line, $m))    $current['status'] = trim($m[1]);
                if (preg_match('/^Auth\s+Method\s*:\s*(.+)/i', $line, $m))    $current['auth_method'] = trim($m[1]);
            }
        }
        if ($current !== null) $clients[] = $current;

        return $clients;
    }

    // ── Port bounce ───────────────────────────────────────────────────────────

    /**
     * Bounces a port (admin-down then admin-up).
     * Tries REST first, falls back to SSH.
     *
     * @return array{success: bool, error: ?string}
     */
    public function bouncePort(ArubaSwitch $switch, string $portId): array
    {
        $portId = self::normalisePortId($portId);

        if ($switch->getPassword() !== null) {
            try {
                return $this->bouncePortRest($switch, $portId);
            } catch (\Throwable $e) {
                if ($switch->getSshPrivateKey() === null) {
                    return ['success' => false, 'error' => $e->getMessage()];
                }
            }
        }

        if ($switch->getSshPrivateKey() !== null || $switch->getPassword() !== null) {
            try {
                return $this->bouncePortSsh($switch, $portId);
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return ['success' => false, 'error' => 'No credentials configured'];
    }

    private function bouncePortRest(ArubaSwitch $switch, string $portId): array
    {
        $cookie  = $this->loginRest($switch);
        $path    = '/system/interfaces/' . $this->encodePort($portId);
        $headers = ['Content-Type: application/json', 'Accept: application/json'];

        try {
            $down = $this->request($switch, 'PUT', $path, $headers, json_encode(['user_config' => ['admin' => 'down']]), $cookie);
            if (!$down['success']) {
                throw new \RuntimeException('Admin-down failed: ' . $down['error']);
            }

            $up = $this->request($switch, 'PUT', $path, $headers, json_encode(['user_config' => ['admin' => 'up']]), $cookie);
            if (!$up['success']) {
                throw new \RuntimeException('Admin-up failed: ' . $up['error']);
            }

            return ['success' => true, 'error' => null];
        } finally {
            $this->logoutRest($switch, $cookie);
        }
    }

    private function bouncePortSsh(ArubaSwitch $switch, string $portId): array
    {
        $ssh = $this->sshConnect($switch);
        $ssh->enablePTY();
        $ssh->setTimeout(10);

        // Open interactive CLI session
        $ssh->exec('', false);

        $ssh->read('/[#>]\s*$/');
        $ssh->write("configure terminal\n");
        $ssh->read('/\(config\)[#>]\s*$/');
        $ssh->write("interface {$portId}\n");
        $ssh->read('/\(config-if\)[#>]\s*$/');
        $ssh->write("shutdown\n");
        $ssh->read('/\(config-if\)[#>]\s*$/');
        $ssh->write("no shutdown\n");
        $ssh->read('/\(config-if\)[#>]\s*$/');
        $ssh->write("end\n");
        $ssh->read('/[#>]\s*$/');

        return ['success' => true, 'error' => null];
    }
}
