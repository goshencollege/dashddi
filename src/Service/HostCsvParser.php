<?php

namespace App\Service;

class HostCsvParser
{
    private const REQUIRED_HEADERS = ['hostname', 'mac_address'];

    public const ALL_HEADERS = [
        'hostname', 'building', 'room', 'host_notes', 'tags',
        'mac_address', 'interface_name', 'subnet', 'ip_address', 'ipv6_address', 'interface_notes',
    ];

    /**
     * Parse CSV content into grouped host entries.
     *
     * Returns:
     *   entries[] = { hostname, building_name, room, notes, tags[], interfaces[] }
     *   interfaces[] = { mac, name, subnet_cidr, ip_address, ipv6_address, notes, row }
     *   errors[] = string (fatal parse errors or per-row validation messages)
     */
    public function parse(string $content): array
    {
        if (trim($content) === '') {
            return ['entries' => [], 'errors' => ['The uploaded file is empty.']];
        }

        $lines = preg_split('/\r\n|\r|\n/', $content);
        $lines = array_values(array_filter($lines, fn(string $l) => trim($l) !== ''));

        if (empty($lines)) {
            return ['entries' => [], 'errors' => ['The uploaded file is empty.']];
        }

        $headerRow = str_getcsv(array_shift($lines));
        $headerMap = [];
        foreach ($headerRow as $i => $h) {
            $headerMap[strtolower(trim($h))] = $i;
        }

        $missing = array_diff(self::REQUIRED_HEADERS, array_keys($headerMap));
        if ($missing) {
            return ['entries' => [], 'errors' => ['Missing required column(s): ' . implode(', ', $missing) . '.']];
        }

        $errors        = [];
        $rowsByHostname = [];
        $rowNumber      = 1;

        foreach ($lines as $line) {
            $rowNumber++;

            $cols = str_getcsv($line);
            $get  = fn(string $key): string => trim($cols[$headerMap[$key] ?? PHP_INT_MAX] ?? '');

            $hostname = $get('hostname');
            if ($hostname === '') {
                $errors[] = "Row $rowNumber: hostname is required.";
                continue;
            }

            $rawMac = $get('mac_address');
            if ($rawMac === '') {
                $errors[] = "Row $rowNumber: mac_address is required.";
                continue;
            }

            if (!$this->isValidMac($rawMac)) {
                $errors[] = "Row $rowNumber: \"$rawMac\" is not a valid MAC address.";
                continue;
            }

            if (!isset($rowsByHostname[$hostname])) {
                $rawTags = $get('tags');
                $tags    = $rawTags !== ''
                    ? array_values(array_filter(array_map('trim', explode(';', $rawTags))))
                    : [];

                $rowsByHostname[$hostname] = [
                    'hostname'      => $hostname,
                    'building_name' => $get('building') ?: null,
                    'room'          => $get('room') ?: null,
                    'notes'         => isset($headerMap['host_notes']) ? ($get('host_notes') ?: null) : null,
                    'tags'          => $tags,
                    'interfaces'    => [],
                ];
            }

            $rowsByHostname[$hostname]['interfaces'][] = [
                'mac'          => $this->normalizeMac($rawMac),
                'name'         => isset($headerMap['interface_name']) ? ($get('interface_name') ?: null) : null,
                'subnet_cidr'  => isset($headerMap['subnet']) ? ($get('subnet') ?: null) : null,
                'ip_address'   => isset($headerMap['ip_address']) ? ($get('ip_address') ?: null) : null,
                'ipv6_address' => isset($headerMap['ipv6_address']) ? ($get('ipv6_address') ?: null) : null,
                'notes'        => isset($headerMap['interface_notes']) ? ($get('interface_notes') ?: null) : null,
                'row'          => $rowNumber,
            ];
        }

        $entries = array_values($rowsByHostname);

        if (empty($entries) && empty($errors)) {
            $errors[] = 'No valid rows were found in the uploaded file.';
        }

        return ['entries' => $entries, 'errors' => $errors];
    }

    public function normalizeMac(string $mac): string
    {
        $hex = preg_replace('/[^0-9a-fA-F]/', '', $mac);
        if (strlen($hex) !== 12) {
            return strtolower($mac);
        }
        return implode(':', str_split(strtolower($hex), 2));
    }

    private function isValidMac(string $mac): bool
    {
        $hex = preg_replace('/[^0-9a-fA-F]/', '', $mac);
        return strlen($hex) === 12;
    }

    public function getTemplateCsvContent(): string
    {
        $headers = implode(',', self::ALL_HEADERS);
        $example = implode(',', [
            'myhost', 'Main Building', '101', 'A sample host', 'tag1;tag2',
            'aa:bb:cc:dd:ee:ff', 'eth0', '192.168.1.0/24', '192.168.1.10', '', '',
        ]);

        return $headers . "\n" . $example . "\n";
    }
}
