<?php

namespace App\Service;

use IPLib\Factory;

class DhcpConfigParser
{
    /**
     * Auto-detect whether content is a Kea (JSON) or ISC DHCPD config.
     */
    public function detectFormat(string $content): string
    {
        $first = ltrim($content);
        return (isset($first[0]) && $first[0] === '{') ? 'kea' : 'dhcpd';
    }

    /**
     * Parse a DHCP config file.
     *
     * Each subnet entry includes a 'name' key: the shared-network name (DHCPD) or
     * the subnet/shared-network name (Kea), or null when none is present.
     *
     * Returns:
     * [
     *   'subnets'      => [['cidr'=>..., 'version'=>4|6, 'gateway'=>?string, 'name'=>?string], ...],
     *   'reservations' => [['hostname'=>..., 'mac'=>..., 'ipv4'=>?string, 'ipv6'=>?string, 'subnet_cidr'=>...], ...],
     *   'errors'       => [...],
     * ]
     */
    public function parse(string $content, string $format): array
    {
        return $format === 'kea' ? $this->parseKea($content) : $this->parseDhcpd($content);
    }

    // -------------------------------------------------------------------------
    // Kea (JSON) parser
    // -------------------------------------------------------------------------

    private function parseKea(string $content): array
    {
        $result = ['subnets' => [], 'reservations' => [], 'errors' => []];

        $data = json_decode($content, true);
        if ($data === null) {
            $result['errors'][] = 'Invalid JSON: ' . json_last_error_msg();
            return $result;
        }

        foreach (['Dhcp4' => ['subnet4', 4], 'Dhcp6' => ['subnet6', 6]] as $section => [$key, $ver]) {
            if (empty($data[$section])) {
                continue;
            }

            // Direct subnets (outside any shared-network)
            foreach ($data[$section][$key] ?? [] as $s) {
                $this->processKeaSubnet($s, $ver, $s['name'] ?? null, $result);
            }

            // Subnets inside shared-networks
            foreach ($data[$section]['shared-networks'] ?? [] as $sn) {
                $networkName = $sn['name'] ?? null;
                foreach ($sn[$key] ?? [] as $s) {
                    // Subnet's own name takes precedence over the shared-network name
                    $this->processKeaSubnet($s, $ver, $s['name'] ?? $networkName, $result);
                }
            }
        }

        return $result;
    }

    private function processKeaSubnet(array $s, int $ver, ?string $name, array &$result): void
    {
        $cidr = $s['subnet'] ?? null;
        if (!$cidr) {
            return;
        }

        $gateway = null;
        if ($ver === 4) {
            foreach ($s['option-data'] ?? [] as $opt) {
                if (($opt['name'] ?? '') === 'routers') {
                    $gateway = trim(explode(',', $opt['data'] ?? '')[0]);
                    break;
                }
            }
        }

        $result['subnets'][] = [
            'cidr'    => $cidr,
            'version' => $ver,
            'gateway' => $gateway ?: null,
            'name'    => $name ?: null,
        ];

        foreach ($s['reservations'] ?? [] as $res) {
            $mac = $res['hw-address'] ?? null;
            if (!$mac) {
                continue;
            }
            $result['reservations'][] = [
                'hostname'    => $res['hostname'] ?? '',
                'mac'         => $mac,
                'ipv4'        => $ver === 4 ? ($res['ip-address'] ?? null) : null,
                'ipv6'        => $ver === 6 ? ($res['ip-addresses'][0] ?? null) : null,
                'subnet_cidr' => $cidr,
            ];
        }
    }

    // -------------------------------------------------------------------------
    // ISC DHCPD parser
    // -------------------------------------------------------------------------

    private function parseDhcpd(string $content): array
    {
        $result = ['subnets' => [], 'reservations' => [], 'errors' => []];

        // Strip comments
        $content = preg_replace('/\/\*.*?\*\//s', '', $content);
        $content = preg_replace('/#[^\n]*/', '', $content);
        $content = preg_replace('/\/\/[^\n]*/', '', $content);

        // Build CIDR → shared-network name map before parsing subnets
        $sharedNames = $this->buildSharedNetworkNames($content);

        // IPv4 subnets: subnet A.B.C.D netmask W.X.Y.Z { ... }
        $v4Blocks = $this->extractBlocks(
            $content,
            'subnet\s+(\d{1,3}(?:\.\d{1,3}){3})\s+netmask\s+(\d{1,3}(?:\.\d{1,3}){3})'
        );
        foreach ($v4Blocks as $block) {
            [$ip, $mask] = $block['captures'];
            $cidr = $ip . '/' . $this->netmaskToCidr($mask);

            $gateway = null;
            if (preg_match('/option\s+routers\s+([\d.]+)\s*;/', $block['body'], $gm)) {
                $gateway = $gm[1];
            }

            $result['subnets'][] = [
                'cidr'    => $cidr,
                'version' => 4,
                'gateway' => $gateway,
                'name'    => $sharedNames[$cidr] ?? null,
            ];

            foreach ($this->extractHosts($block['body'], $cidr, 4) as $res) {
                $result['reservations'][] = $res;
            }
        }

        // IPv6 subnets: subnet6 X::/nn { ... }
        $v6Blocks = $this->extractBlocks($content, 'subnet6\s+([0-9a-fA-F:]+\/\d{1,3})');
        foreach ($v6Blocks as $block) {
            $cidr = $block['captures'][0];
            $result['subnets'][] = [
                'cidr'    => $cidr,
                'version' => 6,
                'gateway' => null,
                'name'    => $sharedNames[$cidr] ?? null,
            ];

            foreach ($this->extractHosts($block['body'], $cidr, 6) as $res) {
                $result['reservations'][] = $res;
            }
        }

        // Top-level hosts (outside any subnet block) — subnet_cidr inferred below
        $usedRanges = array_merge(
            array_map(fn($b) => [$b['start'], $b['end']], $v4Blocks),
            array_map(fn($b) => [$b['start'], $b['end']], $v6Blocks)
        );
        foreach ($this->extractTopLevelHosts($content, $usedRanges) as $res) {
            $result['reservations'][] = $res;
        }

        // For any reservation without a subnet_cidr, infer it from the fixed-address.
        // This is the common case: hosts defined at global scope in dhcpd.conf.
        $this->inferSubnetCidrs($result['reservations'], $result['subnets']);

        return $result;
    }

    /**
     * Build a CIDR → shared-network name map by scanning all shared-network blocks.
     * Handles both quoted ("name") and unquoted (name) shared-network names.
     */
    private function buildSharedNetworkNames(string $content): array
    {
        $names = [];
        foreach ($this->extractBlocks($content, 'shared-network\s+"?([^"{\s]+)"?') as $block) {
            $networkName = $block['captures'][0];
            foreach ($this->extractBlocks($block['body'], 'subnet\s+(\d{1,3}(?:\.\d{1,3}){3})\s+netmask\s+(\d{1,3}(?:\.\d{1,3}){3})') as $sb) {
                [$ip, $mask] = $sb['captures'];
                $names[$ip . '/' . $this->netmaskToCidr($mask)] = $networkName;
            }
            foreach ($this->extractBlocks($block['body'], 'subnet6\s+([0-9a-fA-F:]+\/\d{1,3})') as $sb) {
                $names[$sb['captures'][0]] = $networkName;
            }
        }
        return $names;
    }

    /**
     * Extract all `host <name> { ... }` blocks from $body, building reservation arrays.
     */
    private function extractHosts(string $body, string $subnetCidr, int $version): array
    {
        $reservations = [];
        foreach ($this->extractBlocks($body, 'host\s+(\S+)') as $hb) {
            $hostname = $hb['captures'][0];
            $mac      = null;
            $fixedV4  = null;
            $fixedV6  = null;

            if (preg_match('/hardware\s+ethernet\s+([\da-fA-F:]+)\s*;/', $hb['body'], $hm)) {
                $mac = $hm[1];
            }
            if (preg_match('/fixed-address\s+([\d.]+)\s*;/', $hb['body'], $fm)) {
                $fixedV4 = $fm[1];
            }
            if (preg_match('/fixed-address6\s+([0-9a-fA-F:]+)\s*;/', $hb['body'], $fm)) {
                $fixedV6 = $fm[1];
            }

            if (!$mac) {
                continue;
            }
            $reservations[] = [
                'hostname'    => $hostname,
                'mac'         => $mac,
                'ipv4'        => $fixedV4,
                'ipv6'        => $fixedV6,
                'subnet_cidr' => $subnetCidr,
            ];
        }
        return $reservations;
    }

    /**
     * Extract host blocks that appear outside all known subnet/subnet6 blocks.
     *
     * @param array<array{0:int,1:int}> $usedRanges character ranges to skip
     */
    private function extractTopLevelHosts(string $content, array $usedRanges): array
    {
        $reservations = [];
        foreach ($this->extractBlocks($content, 'host\s+(\S+)') as $hb) {
            // Skip if this host block falls inside a subnet block
            $inside = false;
            foreach ($usedRanges as [$start, $end]) {
                if ($hb['start'] >= $start && $hb['end'] <= $end) {
                    $inside = true;
                    break;
                }
            }
            if ($inside) {
                continue;
            }

            $hostname = $hb['captures'][0];
            $mac      = null;
            $fixedV4  = null;
            $fixedV6  = null;

            if (preg_match('/hardware\s+ethernet\s+([\da-fA-F:]+)\s*;/', $hb['body'], $hm)) {
                $mac = $hm[1];
            }
            if (preg_match('/fixed-address\s+([\d.]+)\s*;/', $hb['body'], $fm)) {
                $fixedV4 = $fm[1];
            }
            if (preg_match('/fixed-address6\s+([0-9a-fA-F:]+)\s*;/', $hb['body'], $fm)) {
                $fixedV6 = $fm[1];
            }

            if (!$mac) {
                continue;
            }
            $reservations[] = [
                'hostname'    => $hostname,
                'mac'         => $mac,
                'ipv4'        => $fixedV4,
                'ipv6'        => $fixedV6,
                'subnet_cidr' => '',
            ];
        }
        return $reservations;
    }

    /**
     * For reservations whose subnet_cidr is empty, infer the subnet by checking which
     * parsed subnet's CIDR contains the reservation's fixed IP address.
     * This handles the common ISC DHCPD pattern where host blocks are defined at
     * global scope alongside (not inside) subnet blocks.
     */
    private function inferSubnetCidrs(array &$reservations, array $subnets): void
    {
        $v4 = array_filter($subnets, fn($s) => $s['version'] === 4);
        $v6 = array_filter($subnets, fn($s) => $s['version'] === 6);

        foreach ($reservations as &$res) {
            if ($res['subnet_cidr'] !== '') {
                continue;
            }
            if ($res['ipv4']) {
                foreach ($v4 as $s) {
                    if ($this->ipInCidr($res['ipv4'], $s['cidr'])) {
                        $res['subnet_cidr'] = $s['cidr'];
                        break;
                    }
                }
            }
            if ($res['subnet_cidr'] === '' && $res['ipv6']) {
                foreach ($v6 as $s) {
                    if ($this->ipInCidr($res['ipv6'], $s['cidr'])) {
                        $res['subnet_cidr'] = $s['cidr'];
                        break;
                    }
                }
            }
        }
        unset($res);
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        $range = Factory::parseRangeString($cidr);
        $addr  = Factory::parseAddressString($ip);
        return $range !== null && $addr !== null && $range->contains($addr);
    }

    /**
     * Find all occurrences of `<headerPattern> {` in $content and extract the balanced body.
     *
     * Returns array of ['captures' => [...], 'body' => string, 'start' => int, 'end' => int].
     */
    private function extractBlocks(string $content, string $headerPattern): array
    {
        $blocks  = [];
        $pattern = '/\b' . $headerPattern . '\s*\{/';
        $offset  = 0;
        $len     = strlen($content);

        while (preg_match($pattern, $content, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $fullMatch = $m[0][0];
            $matchPos  = $m[0][1];
            $bodyStart = $matchPos + strlen($fullMatch);

            $captures = [];
            for ($i = 1; isset($m[$i]); $i++) {
                $captures[] = $m[$i][0];
            }

            // Walk forward to find the balanced closing brace
            $depth = 1;
            $pos   = $bodyStart;
            while ($pos < $len && $depth > 0) {
                if ($content[$pos] === '{') {
                    $depth++;
                } elseif ($content[$pos] === '}') {
                    $depth--;
                }
                $pos++;
            }

            $body     = substr($content, $bodyStart, $pos - $bodyStart - 1);
            $blocks[] = [
                'captures' => $captures,
                'body'     => $body,
                'start'    => $matchPos,
                'end'      => $pos,
            ];

            $offset = $pos;
        }

        return $blocks;
    }

    private function netmaskToCidr(string $netmask): int
    {
        $parts = explode('.', $netmask);
        if (count($parts) !== 4) {
            return 0;
        }
        $binary = '';
        foreach ($parts as $part) {
            $binary .= str_pad(decbin((int) $part), 8, '0', STR_PAD_LEFT);
        }
        return strlen(rtrim($binary, '0'));
    }
}
