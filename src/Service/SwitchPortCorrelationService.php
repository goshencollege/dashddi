<?php

namespace App\Service;

use App\Entity\NetworkInterface;

/**
 * Merges a live Aruba CX port scan (ArubaCxService::scanSwitch()) with DashDDI's
 * cached ClearPass-derived switch/port data, producing one view model per port and
 * flagging discrepancies between the two. Pure logic — no I/O — so it's unit
 * testable with crafted fixtures.
 */
class SwitchPortCorrelationService
{
    /** Ports with more live MACs than this are treated as uplinks/trunks: per-MAC
     *  "unregistered"/"stale" noise is suppressed, since that's expected there. */
    private const UPLINK_MAC_THRESHOLD = 8;

    /**
     * @param  array<string, array{status: ?string, speed: ?string, macs: list<array{mac: string, vlan: ?string}>, clients: list<array{mac: ?string, ip: ?string, vlan: ?string, role: ?string, status: ?string, authMethod: ?string}>, lldp: array{neighborName: ?string, neighborPort: ?string}}> $scanPorts
     * @param  array<string, NetworkInterface[]>                                                                                                                                                                                                                                          $cachedGroups
     * @param  array<string, NetworkInterface>                                                                                                                                                                                                                                            $knownIfacesByMac keyed by lowercase MAC
     * @param  array<int, string>                                                                                                                                                                                                                                                         $lastSeenSources interface id => 'clearpass'|'live_scan', for whichever most recently advanced lastAuthAt
     * @return array<string, array{
     *     port: string,
     *     cached: list<array{interfaceId: ?int, hostId: ?int, hostName: ?string, name: ?string, mac: string, lastAuthAt: ?string, lastDhcpAt: ?string, lastSeenSource: ?string}>,
     *     live: array{status: ?string, speed: ?string, lldpNeighborName: ?string, lldpNeighborPort: ?string, isUplink: bool, macs: list<array{mac: string, role: ?string, status: ?string, authMethod: ?string, known: bool, hostId: ?int, hostName: ?string, interfaceId: ?int, interfaceName: ?string}>},
     *     discrepancies: list<array{type: string, message: string, mac: ?string}>
     * }>
     */
    public function correlate(array $scanPorts, array $cachedGroups, array $knownIfacesByMac, string $switchIp, array $lastSeenSources = []): array
    {
        $liveMacToPorts = $this->buildLiveMacToPorts($scanPorts);

        $ports = [];

        foreach (array_keys($scanPorts) as $port) {
            $ports[$port] = $this->buildLivePort($port, $scanPorts[$port], $knownIfacesByMac, $switchIp);
        }
        foreach (array_keys($cachedGroups) as $port) {
            if (!isset($ports[$port])) {
                $ports[$port] = $this->emptyPort($port);
            }
        }

        foreach ($cachedGroups as $port => $ifaces) {
            $ports[$port]['cached'] = array_map(
                fn (NetworkInterface $iface) => [
                    'interfaceId'    => $iface->getId(),
                    'hostId'         => $iface->getHost()?->getId(),
                    'hostName'       => $iface->getHost()?->getName(),
                    'name'           => $iface->getName(),
                    'mac'            => $iface->getMacAddress(),
                    'lastAuthAt'     => $iface->getLastAuthAt()?->format(DATE_ATOM),
                    'lastDhcpAt'     => $iface->getLastDhcpAt()?->format(DATE_ATOM),
                    'lastSeenSource' => $lastSeenSources[$iface->getId()] ?? null,
                ],
                $ifaces,
            );
        }

        foreach ($ports as $port => &$data) {
            $data['discrepancies'] = array_merge(
                $data['discrepancies'],
                $this->cachedDiscrepancies($port, $data['cached'], $liveMacToPorts, $data['live']['isUplink']),
            );
        }
        unset($data);

        uksort($ports, 'strnatcasecmp');

        return $ports;
    }

    /** @return array<string, string[]> lowercase mac => ports it was seen live on */
    private function buildLiveMacToPorts(array $scanPorts): array
    {
        $map = [];
        foreach ($scanPorts as $port => $data) {
            $macs = array_unique(array_merge(
                array_map(fn ($m) => strtolower($m['mac']), $data['macs'] ?? []),
                array_filter(array_map(fn ($c) => $c['mac'] !== null ? strtolower($c['mac']) : null, $data['clients'] ?? [])),
            ));
            foreach ($macs as $mac) {
                $map[$mac][] = $port;
            }
        }
        return $map;
    }

    private function emptyPort(string $port): array
    {
        return [
            'port'   => $port,
            'cached' => [],
            'live'   => [
                'status'           => null,
                'speed'            => null,
                'lldpNeighborName' => null,
                'lldpNeighborPort' => null,
                'isUplink'         => false,
                'macs'             => [],
            ],
            'discrepancies' => [],
        ];
    }

    private function buildLivePort(string $port, array $scan, array $knownIfacesByMac, string $switchIp): array
    {
        $merged = $this->mergeLiveMacs($scan);
        $isUplink = count($merged) > self::UPLINK_MAC_THRESHOLD;

        $macs          = [];
        $discrepancies = [];

        foreach ($merged as $mac => $entry) {
            $iface = $knownIfacesByMac[$mac] ?? null;

            $macs[] = [
                'mac'           => $mac,
                'role'          => $entry['role'],
                'status'        => $entry['status'],
                'authMethod'    => $entry['authMethod'],
                'known'         => $iface !== null,
                'hostId'        => $iface?->getHost()?->getId(),
                'hostName'      => $iface?->getHost()?->getName(),
                'interfaceId'   => $iface?->getId(),
                'interfaceName' => $iface?->getName(),
            ];

            if ($iface === null) {
                if (!$isUplink) {
                    $discrepancies[] = [
                        'type'    => 'unregistered',
                        'message' => "Unregistered device {$mac} seen live on this port.",
                        'mac'     => $mac,
                    ];
                }
                continue;
            }

            if ($iface->getSwitchIp() === $switchIp
                && $iface->getSwitchPort() !== null
                && strcasecmp(trim($iface->getSwitchPort()), $port) !== 0
            ) {
                $discrepancies[] = [
                    'type'    => 'moved',
                    'message' => "{$mac} is live here, but DashDDI's cache still shows it on port {$iface->getSwitchPort()}.",
                    'mac'     => $mac,
                ];
            }
        }

        return [
            'port'   => $port,
            'cached' => [],
            'live'   => [
                'status'           => $scan['status'] ?? null,
                'speed'            => $scan['speed'] ?? null,
                'lldpNeighborName' => $scan['lldp']['neighborName'] ?? null,
                'lldpNeighborPort' => $scan['lldp']['neighborPort'] ?? null,
                'isUplink'         => $isUplink,
                'macs'             => $macs,
            ],
            'discrepancies' => $discrepancies,
        ];
    }

    /**
     * Merges the MAC-address-table entries and port-access clients for one port into
     * a single per-MAC record (role/status/authMethod come from port-access clients
     * when available).
     *
     * @return array<string, array{role: ?string, status: ?string, authMethod: ?string}>
     */
    private function mergeLiveMacs(array $scan): array
    {
        $merged = [];

        foreach ($scan['macs'] ?? [] as $entry) {
            $mac = strtolower($entry['mac']);
            $merged[$mac] = ['role' => null, 'status' => null, 'authMethod' => null];
        }

        foreach ($scan['clients'] ?? [] as $client) {
            if ($client['mac'] === null) continue;
            $mac = strtolower($client['mac']);
            $merged[$mac] = [
                'role'       => $client['role'],
                'status'     => $client['status'],
                'authMethod' => $client['authMethod'],
            ];
        }

        return $merged;
    }

    /** @return list<array{type: string, message: string, mac: ?string}> */
    private function cachedDiscrepancies(string $port, array $cached, array $liveMacToPorts, bool $isUplink): array
    {
        $discrepancies = [];

        foreach ($cached as $iface) {
            $mac = strtolower($iface['mac']);
            $liveOnPorts = $liveMacToPorts[$mac] ?? [];

            if (in_array($port, $liveOnPorts, true)) {
                continue; // confirmed live here — no discrepancy
            }

            if (!empty($liveOnPorts)) {
                $discrepancies[] = [
                    'type'    => 'moved',
                    'message' => "{$iface['mac']} is cached here, but was seen live on port(s): " . implode(', ', $liveOnPorts) . '.',
                    'mac'     => $iface['mac'],
                ];
            } elseif (!$isUplink) {
                $discrepancies[] = [
                    'type'    => 'stale',
                    'message' => "{$iface['mac']} is cached here but was not seen anywhere in the live scan.",
                    'mac'     => $iface['mac'],
                ];
            }
        }

        return $discrepancies;
    }
}
