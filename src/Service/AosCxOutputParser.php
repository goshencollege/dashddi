<?php

namespace App\Service;

/**
 * Pure parsing helpers for Aruba AOS-CX CLI "show" command output. No I/O —
 * kept separate from ArubaCxService so this parsing logic stays unit-testable
 * without an SSH connection.
 */
class AosCxOutputParser
{
    /**
     * Parses `show interface brief` into per-port link status/speed.
     *
     * Deliberately does NOT measure column positions from the header line: AOS-CX
     * pads header labels ("Enabled", "Status") to the label's own width rather than
     * the data column's width, so a wide label can butt up against the next one with
     * only a single space while the data below it (short values like "yes") stays
     * separated by several — header-derived column offsets silently misalign against
     * the data. Instead this parses each physical-port row by its fixed field order:
     * `port  native-vlan  mode  type  enabled  status  [reason...]  speed  [description...]`,
     * scanning forward from Status for the first purely-numeric-or-"--" token to find
     * Speed (anything between Status and that token is the free-text Reason column,
     * e.g. "Waiting for link", which is present only for down ports).
     *
     * @return array<string, array{status: ?string, speed: ?string}>
     */
    public static function parseInterfaceBrief(string $output): array
    {
        $ports = [];

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $tokens = preg_split('/\s+/', $line);
            if (count($tokens) < 6 || !preg_match('/^\d+\/\d+\/\d+$/', $tokens[0])) continue;

            $port   = $tokens[0];
            $status = $tokens[5];

            $speed = null;
            for ($i = 6; $i < count($tokens); $i++) {
                if (preg_match('/^(\d+|--)$/', $tokens[$i])) {
                    $speed = $tokens[$i];
                    break;
                }
            }

            $ports[$port] = [
                'status' => strtolower($status),
                'speed'  => ($speed !== null && $speed !== '--') ? $speed : null,
            ];
        }

        return $ports;
    }

    /**
     * Parses a whole-switch (no `interface` filter) `show port-access clients` dump
     * into a per-port list of clients. Unlike ArubaCxService::parsePortAccessOutput()
     * (which parses a single already-known port's output and doesn't need to capture
     * the port or MAC columns), this captures the Port column explicitly and treats
     * Client-Name as the MAC address when it looks like one (AOS-CX defaults the
     * client name to the MAC for clients without an LLDP/DHCP-supplied name).
     *
     * @return array<string, list<array{mac: ?string, ip: ?string, vlan: ?string, role: ?string, status: ?string, authMethod: ?string}>>
     */
    public static function parsePortAccessClients(string $output): array
    {
        $ports = [];
        $lines = explode("\n", $output);

        $headerLine = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/\bPort\b.+\bClient-Name\b/i', $line)) {
                $headerLine = $i;
                break;
            }
        }

        if ($headerLine === null) {
            return $ports;
        }

        $cols   = preg_split('/\s{2,}/', trim($lines[$headerLine]));
        $colMap = [];
        foreach ($cols as $ci => $col) {
            $col = strtolower(trim($col));
            if ($col === 'port')                $colMap['port']  = $ci;
            if (str_contains($col, 'client-name')) $colMap['name'] = $ci;
            if (str_contains($col, 'ipv4'))      $colMap['ip']    = $ci;
            if (str_contains($col, 'role'))      $colMap['role']  = $ci;
            if ($col === 'vlan')                 $colMap['vlan']  = $ci;
            if ($col === 'flags')                $colMap['flags'] = $ci;
        }

        for ($i = $headerLine + 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '' || preg_match('/^-+$/', $line)) continue;

            $parts = preg_split('/\s{2,}/', $line);
            if (count($parts) < 2) continue;

            $port = isset($colMap['port']) ? ($parts[$colMap['port']] ?? null) : null;
            if ($port === null || !preg_match('/^\d+\/\d+\/\d+$/', $port)) continue;

            $name     = isset($colMap['name'])  ? ($parts[$colMap['name']]  ?? null) : null;
            $ip       = isset($colMap['ip'])    ? ($parts[$colMap['ip']]    ?? null) : null;
            $role     = isset($colMap['role'])  ? ($parts[$colMap['role']]  ?? null) : null;
            $vlanRaw  = isset($colMap['vlan'])  ? ($parts[$colMap['vlan']]  ?? null) : null;
            $flagsRaw = isset($colMap['flags']) ? ($parts[$colMap['flags']] ?? null) : null;
            $vlan     = $vlanRaw ? (string) preg_replace('/^\([a-z]+\)/', '', $vlanRaw) : null;

            $mac = ($name !== null && preg_match('/^([0-9a-fA-F]{2}[:.\-]){5}[0-9a-fA-F]{2}$/', $name))
                ? strtolower(str_replace(['-', '.'], ':', $name))
                : null;

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

            $ports[$port][] = [
                'mac'        => $mac,
                'ip'         => $ip !== null && $ip !== '' ? $ip : null,
                'vlan'       => $vlan,
                'role'       => $role !== null && $role !== '' ? $role : null,
                'status'     => $status,
                'authMethod' => $authMethod,
            ];
        }

        return $ports;
    }

    /**
     * Parses `show mac-address-table` into a per-port list of {mac, vlan} entries.
     *
     * @return array<string, list<array{mac: string, vlan: ?string}>>
     */
    public static function parseMacAddressTable(string $output): array
    {
        $ports = [];
        $lines = explode("\n", $output);

        $headerLine = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/MAC\s+Address/i', $line) && preg_match('/\bPort\b/i', $line)) {
                $headerLine = $i;
                break;
            }
        }

        if ($headerLine === null) {
            return $ports;
        }

        $cols   = preg_split('/\s{2,}/', trim($lines[$headerLine]));
        $colMap = [];
        foreach ($cols as $ci => $col) {
            $col = strtolower(trim($col));
            if (str_contains($col, 'mac'))   $colMap['mac']  = $ci;
            if ($col === 'vlan')             $colMap['vlan'] = $ci;
            if ($col === 'port')             $colMap['port'] = $ci;
        }

        for ($i = $headerLine + 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '' || preg_match('/^-+$/', $line)) continue;

            $parts = preg_split('/\s{2,}/', $line);
            if (count($parts) < 2) continue;

            $mac  = isset($colMap['mac'])  ? ($parts[$colMap['mac']]  ?? null) : null;
            $vlan = isset($colMap['vlan']) ? ($parts[$colMap['vlan']] ?? null) : null;
            $port = isset($colMap['port']) ? ($parts[$colMap['port']] ?? null) : null;

            if ($mac === null || $port === null || !preg_match('/^([0-9a-fA-F]{2}[:.\-]){5}[0-9a-fA-F]{2}$/', $mac)) {
                continue;
            }

            $ports[$port][] = [
                'mac'  => strtolower(str_replace(['-', '.'], ':', $mac)),
                'vlan' => $vlan !== null && $vlan !== '' ? $vlan : null,
            ];
        }

        return $ports;
    }

    /**
     * Parses `show lldp neighbor-info` into per-local-port neighbor summary.
     *
     * @return array<string, array{neighborName: ?string, neighborPort: ?string, neighborMac: ?string}>
     */
    public static function parseLldpNeighborInfo(string $output): array
    {
        $ports = [];
        $lines = explode("\n", $output);

        // Table format: a header row containing "Local Port" plus neighbor columns.
        $headerLine = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/Local\s+Port/i', $line)) {
                $headerLine = $i;
                break;
            }
        }

        if ($headerLine !== null) {
            $cols   = preg_split('/\s{2,}/', trim($lines[$headerLine]));
            $colMap = [];
            foreach ($cols as $ci => $col) {
                $col = strtolower(trim($col));
                if (str_contains($col, 'local port'))                      $colMap['port'] = $ci;
                if (str_contains($col, 'name'))                            $colMap['name'] = $ci;
                if (str_contains($col, 'port id') || str_contains($col, 'port desc')) $colMap['neighborPort'] = $ci;
                if (str_contains($col, 'chassis id'))                      $colMap['chassisId'] = $ci;
            }

            for ($i = $headerLine + 1; $i < count($lines); $i++) {
                $line = trim($lines[$i]);
                if ($line === '' || preg_match('/^-+$/', $line)) continue;

                $parts = preg_split('/\s{2,}/', $line);
                if (count($parts) < 1) continue;

                $port = $parts[$colMap['port'] ?? 0] ?? null;
                if ($port === null || !preg_match('/^\d+\/\d+\/\d+$/', $port)) continue;

                $name      = isset($colMap['name'])       ? ($parts[$colMap['name']]       ?? null) : null;
                $np        = isset($colMap['neighborPort']) ? ($parts[$colMap['neighborPort']] ?? null) : null;
                $chassisId = isset($colMap['chassisId'])  ? ($parts[$colMap['chassisId']]  ?? null) : null;

                $ports[$port] = [
                    'neighborName' => $name !== null && $name !== '' ? $name : null,
                    'neighborPort' => $np   !== null && $np   !== '' ? $np   : null,
                    'neighborMac'  => self::asMacAddress($chassisId),
                ];
            }

            if (!empty($ports)) {
                return $ports;
            }
        }

        // Key-value block format ("Local Port : 1/1/1" / "System Name : ..." / "Port ID : ..." / "Chassis Id : ...")
        $current     = null;
        $currentPort = null;
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^Local\s+Port\s*:\s*(\S+)/i', $line, $m)) {
                if ($currentPort !== null && $current !== null) {
                    $ports[$currentPort] = $current;
                }
                $currentPort = $m[1];
                $current     = ['neighborName' => null, 'neighborPort' => null, 'neighborMac' => null];
            } elseif ($current !== null) {
                if (preg_match('/^(System\s+Name|Neighbor\s+Name)\s*:\s*(.+)/i', $line, $m)) {
                    $current['neighborName'] = trim($m[2]);
                }
                if (preg_match('/^Port\s+ID\s*:\s*(.+)/i', $line, $m)) {
                    $current['neighborPort'] = trim($m[1]);
                }
                if (preg_match('/^Chassis\s+Id\s*:\s*(.+)/i', $line, $m)) {
                    $current['neighborMac'] = self::asMacAddress(trim($m[1]));
                }
            }
        }
        if ($currentPort !== null && $current !== null) {
            $ports[$currentPort] = $current;
        }

        return $ports;
    }

    /** Returns the value lowercased if it looks like a MAC address, else null. */
    private static function asMacAddress(?string $value): ?string
    {
        if ($value === null || !preg_match('/^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/', $value)) {
            return null;
        }
        return strtolower($value);
    }
}
