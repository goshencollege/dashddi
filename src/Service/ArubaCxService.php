<?php

namespace App\Service;

use App\Entity\ArubaSwitch;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

class ArubaCxService
{
    public function __construct(private readonly SshKeyService $sshKeys) {}
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
        $normalized = preg_replace('/[\s,]+.*$/', '', trim($portId)) ?? trim($portId);

        if (!preg_match('/^\d+\/\d+\/\d+$/', $normalized)) {
            throw new \InvalidArgumentException('Invalid port ID: ' . $portId);
        }

        return $normalized;
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

        $this->sshKeys->verifyAndLearnHostKey($ssh, $ip);

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
        $ssh->enablePTY();
        $ssh->setTimeout(10);

        $ssh->exec('', false);
        $out  = (string) $ssh->read('/[#>]\s*$/');
        $ssh->setTimeout(5);    // show commands can be slower than config commands
        $ssh->write("show port-access clients interface {$portId}\n");
        $out .= (string) $ssh->read('/[#>]\s*$/');

        return ['clients' => $this->parsePortAccessOutput($out), 'raw' => trim($out), 'via' => 'ssh', 'error' => null];
    }

    private function parsePortAccessOutput(string $output): array
    {
        $clients = [];
        $lines   = explode("\n", $output);

        // Detect table format by header line
        $headerLine = null;
        $headerType = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/MAC\s+Address/i', $line)) {
                $headerLine = $i;
                $headerType = 'mac';
                break;
            }
            // Aruba CX "show port-access clients" format: Port  Client-Name  IPv4-Address  User-Role  VLAN  Flags
            if (preg_match('/\bPort\b.+\bClient-Name\b/i', $line)) {
                $headerLine = $i;
                $headerType = 'port';
                break;
            }
        }

        if ($headerLine !== null) {
            $cols   = preg_split('/\s{2,}/', trim($lines[$headerLine]));
            $colMap = [];
            foreach ($cols as $ci => $col) {
                $col = strtolower(trim($col));
                if ($headerType === 'mac') {
                    if (str_contains($col, 'mac'))                                    $colMap['mac']         = $ci;
                    if (str_contains($col, 'ip'))                                     $colMap['ip']          = $ci;
                    if (str_contains($col, 'role'))                                   $colMap['role']        = $ci;
                    if (str_contains($col, 'status') || str_contains($col, 'auth s')) $colMap['status']     = $ci;
                    if (str_contains($col, 'method'))                                 $colMap['auth_method'] = $ci;
                } else {
                    if (str_contains($col, 'ipv4'))   $colMap['ip']    = $ci;
                    if (str_contains($col, 'role'))   $colMap['role']  = $ci;
                    if ($col === 'vlan')               $colMap['vlan']  = $ci;
                    if ($col === 'flags')              $colMap['flags'] = $ci;
                }
            }

            for ($i = $headerLine + 1; $i < count($lines); $i++) {
                $line = trim($lines[$i]);
                if ($line === '' || preg_match('/^-+/', $line)) continue;
                $parts = preg_split('/\s{2,}/', $line);
                if (count($parts) < 2) continue;

                if ($headerType === 'mac') {
                    $clients[] = [
                        'mac'         => strtolower($parts[$colMap['mac'] ?? 0] ?? ''),
                        'vlan'        => null,
                        'role'        => $parts[$colMap['role'] ?? 2] ?? null,
                        'status'      => $parts[$colMap['status'] ?? 3] ?? null,
                        'auth_method' => $parts[$colMap['auth_method'] ?? 4] ?? null,
                    ];
                } else {
                    // Port/Client-Name format — decode VLAN prefix and Flags field
                    $vlanRaw  = isset($colMap['vlan'])  ? ($parts[$colMap['vlan']]  ?? null) : null;
                    $flagsRaw = isset($colMap['flags']) ? ($parts[$colMap['flags']] ?? null) : null;
                    $vlan     = $vlanRaw ? (string) preg_replace('/^\([a-z]+\)/', '', $vlanRaw) : null;

                    $authMethod = null;
                    $status     = null;
                    if ($flagsRaw !== null) {
                        $fp         = explode('|', $flagsRaw);
                        $authMethod = match ($fp[0] ?? '') {
                            '1x' => '802.1X', 'ma' => 'MAC-Auth',
                            'ps' => 'Port-Security', 'dp' => 'Device-Profile',
                            default => $fp[0] ?: null,
                        };
                        $status = match ($fp[3] ?? '') {
                            's' => 'Success', 'f' => 'Failed',
                            'p' => 'In-Progress', 'd' => 'Role-Download-Failed',
                            default => $fp[3] ?: null,
                        };
                    }

                    $clients[] = [
                        'mac'         => null,
                        'vlan'        => $vlan,
                        'role'        => isset($colMap['role'])  ? ($parts[$colMap['role']]  ?? null) : null,
                        'status'      => $status,
                        'auth_method' => $authMethod,
                    ];
                }
            }
            return $clients;
        }

        // Key-value format ("Client MAC : xx:xx:xx:xx:xx:xx")
        $current = null;
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^Client\s+MAC\s*:\s*([0-9a-f:]+)/i', $line, $m)) {
                if ($current !== null) $clients[] = $current;
                $current = ['mac' => strtolower($m[1]), 'vlan' => null, 'role' => null, 'status' => null, 'auth_method' => null];
            } elseif ($current !== null) {
                if (preg_match('/^Client\s+Role\s*:\s*(.+)/i', $line, $m)) $current['role']        = trim($m[1]);
                if (preg_match('/^Auth\s+Status\s*:\s*(.+)/i', $line, $m)) $current['status']      = trim($m[1]);
                if (preg_match('/^Auth\s+Method\s*:\s*(.+)/i', $line, $m)) $current['auth_method'] = trim($m[1]);
            }
        }
        if ($current !== null) $clients[] = $current;

        return $clients;
    }

    // ── Whole-switch scan ─────────────────────────────────────────────────────

    /**
     * Fetches interface link status/speed, port-access clients, the MAC address
     * table, and LLDP neighbor info for every port, preferring REST over CLI for
     * each independently: typed JSON calls instead of scraping text tables (which
     * turned out to have header/data column misalignment on real hardware). Any
     * of the four falls back to its SSH-parsed `show` command equivalent (all run
     * in a single SSH session) when REST credentials aren't configured, the
     * request fails, or it parses to zero entries (e.g. a firmware reporting these
     * attributes under different JSON keys than assumed here). If REST supplies
     * all four, no SSH connection is opened at all.
     *
     * @return array{ports: array<string, array{status: ?string, speed: ?string, macs: list<array{mac: string, vlan: ?string}>, clients: list<array{mac: ?string, ip: ?string, vlan: ?string, role: ?string, status: ?string, authMethod: ?string}>, lldp: array{neighborName: ?string, neighborPort: ?string}}>, raw: array{interfaceBrief: string, portAccess: string, macTable: string, lldp: string}, error: ?string}
     */
    public function scanSwitch(ArubaSwitch $creds, string $ip): array
    {
        set_time_limit(60);

        if ($creds->getSshPrivateKey() === null && $creds->getPassword() === null) {
            return ['ports' => [], 'raw' => $this->emptyRaw(), 'error' => 'No credentials configured'];
        }

        $interfaceBrief = $portAccess = $macTable = $lldp = null;
        $raw            = $this->emptyRaw();

        if ($creds->getPassword() !== null) {
            try {
                $rest = $this->getInterfaceBriefRest($creds, $ip);
                if (!empty($rest['ports'])) {
                    $interfaceBrief        = $rest['ports'];
                    $raw['interfaceBrief'] = $rest['raw'];
                }
            } catch (\Throwable $e) {
                // Fall through to the SSH-parsed `show interface brief` below.
            }

            try {
                $detail = $this->getInterfacesDeepRest($creds, $ip);

                $pa = $this->extractPortAccessClientsRest($detail['data']);
                if (!empty($pa)) {
                    $portAccess        = $pa;
                    $raw['portAccess'] = $detail['raw'];
                }
            } catch (\Throwable $e) {
                // Fall through to the SSH-parsed `show port-access clients` below.
            }

            try {
                $mac = $this->getMacTableRest($creds, $ip);
                if (!empty($mac['ports'])) {
                    $macTable        = $mac['ports'];
                    $raw['macTable'] = $mac['raw'];
                }
            } catch (\Throwable $e) {
                // Fall through to the SSH-parsed `show mac-address-table` below.
            }

            try {
                $lldpRest = $this->getLldpNeighborsRest($creds, $ip);
                if (!empty($lldpRest['ports'])) {
                    $lldp        = $lldpRest['ports'];
                    $raw['lldp'] = $lldpRest['raw'];
                }
            } catch (\Throwable $e) {
                // Fall through to the SSH-parsed `show lldp neighbor-info` below.
            }
        }

        try {
            return $this->scanSwitchSsh($creds, $ip, $interfaceBrief, $portAccess, $macTable, $lldp, $raw);
        } catch (\Throwable $e) {
            return ['ports' => [], 'raw' => $raw, 'error' => $e->getMessage()];
        }
    }

    private function emptyRaw(): array
    {
        return ['interfaceBrief' => '', 'portAccess' => '', 'macTable' => '', 'lldp' => ''];
    }

    /**
     * @return array{ports: array<string, array{status: ?string, speed: ?string}>, raw: string}
     */
    private function getInterfaceBriefRest(ArubaSwitch $creds, string $ip): array
    {
        $cookie = $this->loginRest($creds, $ip);
        try {
            $result = $this->request($creds, $ip, 'GET', '/system/interfaces?depth=2', ['Accept: application/json'], '', $cookie);
            if (!$result['success']) {
                throw new \RuntimeException($result['error']);
            }

            $data  = json_decode($result['body'], true) ?? [];
            $ports = [];

            foreach ($data as $key => $iface) {
                if (!is_array($iface)) continue;

                $name = (string) ($iface['name'] ?? rawurldecode((string) $key));
                if (!preg_match('/^\d+\/\d+\/\d+$/', $name)) continue;

                // Field names per AOS-CX's documented System::Interface attributes.
                // Checked both flat and nested under a "status" object in case a
                // firmware version structures the response differently.
                $statusBlock = is_array($iface['status'] ?? null) ? $iface['status'] : [];
                $linkState   = $iface['link_state']  ?? $statusBlock['link_state']  ?? null;
                $adminState  = $iface['admin_state'] ?? $statusBlock['admin_state'] ?? null;
                $linkSpeed   = $iface['link_speed']  ?? $statusBlock['link_speed']  ?? null;

                $status = $linkState ?? $adminState;
                $speed  = null;
                if (is_numeric($linkSpeed) && (float) $linkSpeed > 0) {
                    // AOS-CX has reported link_speed in both bps and Mb/s across
                    // versions in the wild — normalise to Mb/s either way.
                    $speed = (float) $linkSpeed >= 1_000_000
                        ? (string) ((int) round((float) $linkSpeed / 1_000_000))
                        : (string) ((int) $linkSpeed);
                }

                $ports[$name] = [
                    'status' => $status !== null ? strtolower((string) $status) : null,
                    'speed'  => $speed,
                ];
            }

            $pretty = json_encode(json_decode($result['body'], true), JSON_PRETTY_PRINT);

            return ['ports' => $ports, 'raw' => $pretty !== false ? $pretty : $result['body']];
        } finally {
            $this->logoutRest($creds, $ip, $cookie);
        }
    }

    /**
     * Fetches the full interface collection at a depth deep enough to expand each
     * interface's `port_access_clients` reference collection into full objects
     * rather than bare URIs.
     *
     * @return array{data: array, raw: string}
     */
    private function getInterfacesDeepRest(ArubaSwitch $creds, string $ip): array
    {
        $cookie = $this->loginRest($creds, $ip);
        try {
            $result = $this->request($creds, $ip, 'GET', '/system/interfaces?depth=4', ['Accept: application/json'], '', $cookie);
            if (!$result['success']) {
                throw new \RuntimeException($result['error']);
            }

            $data   = json_decode($result['body'], true) ?? [];
            $pretty = json_encode($data, JSON_PRETTY_PRINT);

            return ['data' => $data, 'raw' => $pretty !== false ? $pretty : $result['body']];
        } finally {
            $this->logoutRest($creds, $ip, $cookie);
        }
    }

    /**
     * @param array $interfaces decoded `/system/interfaces?depth=4` body
     * @return array<string, list<array{mac: ?string, ip: ?string, vlan: ?string, role: ?string, status: ?string, authMethod: ?string}>>
     */
    private function extractPortAccessClientsRest(array $interfaces): array
    {
        $ports = [];

        foreach ($interfaces as $key => $iface) {
            if (!is_array($iface)) continue;

            $name = (string) ($iface['name'] ?? rawurldecode((string) $key));
            if (!preg_match('/^\d+\/\d+\/\d+$/', $name)) continue;

            $clients = $iface['port_access_clients'] ?? [];
            if (!is_array($clients)) continue;

            foreach ($clients as $clientKey => $entry) {
                // Still a bare URI reference — the requested depth wasn't enough to
                // expand it on this firmware version.
                if (!is_array($entry)) continue;

                $vlanId = array_key_first($entry['access_vlan'] ?? []);

                $ports[$name][] = [
                    'mac'        => $entry['mac'] ?? (is_string($clientKey) ? rawurldecode($clientKey) : null),
                    'ip'         => $entry['ip'] ?? null,
                    'vlan'       => $vlanId !== null ? (string) $vlanId : null,
                    'role'       => array_key_first($entry['applied_role'] ?? []) ?? null,
                    'status'     => $entry['client_state'] ?? null,
                    'authMethod' => $entry['onboarded_method'] ?? null,
                ];
            }
        }

        return $ports;
    }

    /**
     * Fetches LLDP neighbor info via REST. AOS-CX doesn't expand `lldp_neighbors`
     * when it's nested inside a `/system/interfaces?depth=N` collection response —
     * it stays an empty/unexpanded reference there regardless of depth — so this
     * queries each interface's own `/system/interfaces/{port}/lldp_neighbors`
     * endpoint directly, one request per port, reusing a single login session.
     *
     * @return array{ports: array<string, array{neighborName: ?string, neighborPort: ?string}>, raw: string}
     */
    private function getLldpNeighborsRest(ArubaSwitch $creds, string $ip): array
    {
        $cookie = $this->loginRest($creds, $ip);
        try {
            $listResult = $this->request($creds, $ip, 'GET', '/system/interfaces?depth=0', ['Accept: application/json'], '', $cookie);
            if (!$listResult['success']) {
                throw new \RuntimeException($listResult['error']);
            }

            $refs     = json_decode($listResult['body'], true) ?? [];
            $ports    = [];
            $rawParts = [];

            foreach (array_keys($refs) as $key) {
                $name = rawurldecode((string) $key);
                if (!preg_match('/^\d+\/\d+\/\d+$/', $name)) continue;

                $path     = '/system/interfaces/' . rawurlencode($name) . '/lldp_neighbors?depth=2';
                $response = $this->request($creds, $ip, 'GET', $path, ['Accept: application/json'], '', $cookie);
                if (!$response['success']) continue; // no LLDP data for this port on this firmware/permission

                $neighbors = json_decode($response['body'], true) ?? [];
                if (empty($neighbors)) continue;

                $rawParts[$name] = $neighbors;

                $first = null;
                foreach ($neighbors as $entry) {
                    if (is_array($entry)) {
                        $first = $entry;
                        break;
                    }
                }
                if ($first === null) continue;

                // The neighbor's descriptive attributes (name, remote port) live
                // under "neighbor_info"; the top-level chassis_id/port_id fields
                // are part of the entry's own key, not necessarily human-readable.
                $info         = is_array($first['neighbor_info'] ?? null) ? $first['neighbor_info'] : [];
                $neighborName = $info['chassis_name'] ?? $first['chassis_id'] ?? null;
                $neighborPort = $info['port_id']      ?? $info['port_description'] ?? $first['port_id'] ?? null;

                $ports[$name] = [
                    'neighborName' => $neighborName !== null ? (string) $neighborName : null,
                    'neighborPort' => $neighborPort !== null ? (string) $neighborPort : null,
                ];
            }

            $pretty = json_encode($rawParts, JSON_PRETTY_PRINT);

            return ['ports' => $ports, 'raw' => $pretty !== false ? $pretty : ''];
        } finally {
            $this->logoutRest($creds, $ip, $cookie);
        }
    }

    /**
     * Builds the switch-wide MAC address table via REST. AOS-CX models learned MACs
     * per-VLAN (`/system/vlans/{vlan}/macs`) rather than as one flat table, so this
     * lists VLANs first and then queries each VLAN's MAC collection.
     *
     * @return array{ports: array<string, list<array{mac: string, vlan: ?string}>>, raw: string}
     */
    private function getMacTableRest(ArubaSwitch $creds, string $ip): array
    {
        $cookie = $this->loginRest($creds, $ip);
        try {
            $vlanResult = $this->request($creds, $ip, 'GET', '/system/vlans?depth=0', ['Accept: application/json'], '', $cookie);
            if (!$vlanResult['success']) {
                throw new \RuntimeException($vlanResult['error']);
            }

            $vlanRefs = json_decode($vlanResult['body'], true) ?? [];
            $ports    = [];
            $rawParts = [];

            foreach (array_keys($vlanRefs) as $vlanKey) {
                $vlanId = rawurldecode((string) $vlanKey);
                $result = $this->request($creds, $ip, 'GET', '/system/vlans/' . rawurlencode($vlanId) . '/macs?depth=2', ['Accept: application/json'], '', $cookie);
                if (!$result['success']) continue; // MAC table not exposed for this VLAN on this firmware

                $macs               = json_decode($result['body'], true) ?? [];
                $rawParts[$vlanId] = $macs;

                foreach ($macs as $macKey => $entry) {
                    if (!is_array($entry)) continue;

                    // "port" is a reference field, represented either as a
                    // {"<port-name>": "<uri>"} map (the convention used elsewhere for
                    // reference attributes) or, on some firmware, a bare port name.
                    $portRef  = $entry['port'] ?? null;
                    $portName = match (true) {
                        is_string($portRef) => $portRef,
                        is_array($portRef)  => (string) (array_key_first($portRef) ?? ''),
                        default             => null,
                    };
                    if ($portName === null || !preg_match('/^\d+\/\d+\/\d+$/', $portName)) continue;

                    $mac             = (string) ($entry['mac_addr'] ?? $macKey);
                    $ports[$portName][] = [
                        'mac'  => strtolower($mac),
                        'vlan' => $vlanId !== '' ? $vlanId : null,
                    ];
                }
            }

            $pretty = json_encode($rawParts, JSON_PRETTY_PRINT);

            return ['ports' => $ports, 'raw' => $pretty !== false ? $pretty : ''];
        } finally {
            $this->logoutRest($creds, $ip, $cookie);
        }
    }

    private function scanSwitchSsh(
        ArubaSwitch $creds,
        string      $ip,
        ?array      $interfaceBrief,
        ?array      $portAccess,
        ?array      $macTable,
        ?array      $lldp,
        array       $raw,
    ): array {
        if ($interfaceBrief === null || $portAccess === null || $macTable === null || $lldp === null) {
            $ssh = $this->sshConnect($creds, $ip);
            $ssh->enablePTY();
            $ssh->setTimeout(10);

            $ssh->exec('', false);
            $ssh->read('/[#>]\s*$/');
            $ssh->setTimeout(8);

            // Disable output pagination for this session — without it, the CLI stops at a
            // "--More--" prompt after ~19-24 lines and waits for a keypress, which our
            // prompt-regex read() can't satisfy, silently truncating anything longer than
            // one screen (this is exactly what shows up as only the first N ports of
            // `show interface brief`). Harmless to send even if unsupported on a given
            // firmware — worst case is a one-line "unknown command" that gets swallowed
            // by the next prompt read.
            $ssh->write("no page\n");
            $ssh->read('/[#>]\s*$/');

            if ($interfaceBrief === null) {
                $ssh->write("show interface brief\n");
                $interfaceBriefOut     = (string) $ssh->read('/[#>]\s*$/');
                $interfaceBrief        = AosCxOutputParser::parseInterfaceBrief($interfaceBriefOut);
                $raw['interfaceBrief'] = trim($interfaceBriefOut);
            }

            if ($portAccess === null) {
                $ssh->write("show port-access clients\n");
                $portAccessOut     = (string) $ssh->read('/[#>]\s*$/');
                $portAccess        = AosCxOutputParser::parsePortAccessClients($portAccessOut);
                $raw['portAccess'] = trim($portAccessOut);
            }

            if ($macTable === null) {
                $ssh->write("show mac-address-table\n");
                $macTableOut     = (string) $ssh->read('/[#>]\s*$/');
                $macTable        = AosCxOutputParser::parseMacAddressTable($macTableOut);
                $raw['macTable'] = trim($macTableOut);
            }

            if ($lldp === null) {
                $ssh->write("show lldp neighbor-info\n");
                $lldpOut     = (string) $ssh->read('/[#>]\s*$/');
                $lldp        = AosCxOutputParser::parseLldpNeighborInfo($lldpOut);
                $raw['lldp'] = trim($lldpOut);
            }
        }

        $interfaceBrief ??= [];
        $portAccess     ??= [];
        $macTable       ??= [];
        $lldp           ??= [];

        $allPorts = array_unique(array_merge(
            array_keys($interfaceBrief),
            array_keys($portAccess),
            array_keys($macTable),
            array_keys($lldp),
        ));

        $ports = [];
        foreach ($allPorts as $port) {
            $ports[$port] = [
                'status'  => $interfaceBrief[$port]['status'] ?? null,
                'speed'   => $interfaceBrief[$port]['speed']  ?? null,
                'macs'    => $macTable[$port] ?? [],
                'clients' => $portAccess[$port] ?? [],
                'lldp'    => $lldp[$port] ?? ['neighborName' => null, 'neighborPort' => null],
            ];
        }

        return ['ports' => $ports, 'raw' => $raw, 'error' => null];
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
        $ssh = $this->sshConnect($creds, $ip);
        $ssh->enablePTY();
        $ssh->setTimeout(10);   // long enough for login banner

        $ssh->exec('', false);
        $out  = (string) $ssh->read('/[#>]\s*$/');
        $ssh->setTimeout(5);    // allow time for banner + command response
        $ssh->write("port-access reauthenticate interface {$portId}\n");
        $out .= (string) $ssh->read('/[#>]\s*$/');

        return ['success' => true, 'error' => null, 'output' => trim($out)];
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
        $ssh = $this->sshConnect($creds, $ip);
        $ssh->enablePTY();
        $ssh->setTimeout(10);   // long enough for login banner

        $ssh->exec('', false);
        $out  = $ssh->read('/[#>]\s*$/');
        $ssh->setTimeout(1);    // fast for subsequent reads
        $ssh->write("configure terminal\n");
        $out .= $ssh->read('/\(config\)[#>]\s*$/');
        $ssh->write("interface {$portId}\n");
        $out .= $ssh->read('/\(config-if\)[#>]\s*$/');
        $ssh->write("shutdown\n");
        $out .= $ssh->read('/\(config-if\)[#>]\s*$/');
        sleep(10);
        $ssh->write("no shutdown\n");
        $out .= $ssh->read('/\(config-if\)[#>]\s*$/');
        $ssh->write("end\n");
        $out .= $ssh->read('/[#>]\s*$/');

        return ['success' => true, 'error' => null, 'output' => trim((string) $out)];
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
        $ssh = $this->sshConnect($creds, $ip);
        $ssh->enablePTY();
        $ssh->setTimeout(10);   // long enough for login banner

        $ssh->exec('', false);
        $out  = $ssh->read('/[#>]\s*$/');
        $ssh->setTimeout(1);    // fast for subsequent reads
        $ssh->write("configure terminal\n");
        $out .= $ssh->read('/\(config\)[#>]\s*$/');
        $ssh->write("interface {$portId}\n");
        $out .= $ssh->read('/\(config-if\)[#>]\s*$/');
        $ssh->write("no power-over-ethernet\n");
        $out .= $ssh->read('/\(config-if\)[#>]\s*$/');
        $ssh->write("shutdown\n");
        $out .= $ssh->read('/\(config-if\)[#>]\s*$/');
        sleep(10);
        $ssh->write("no shutdown\n");
        $out .= $ssh->read('/\(config-if\)[#>]\s*$/');
        $ssh->write("power-over-ethernet\n");
        $out .= $ssh->read('/\(config-if\)[#>]\s*$/');
        $ssh->write("end\n");
        $out .= $ssh->read('/[#>]\s*$/');

        return ['success' => true, 'error' => null, 'output' => trim((string) $out)];
    }
}
