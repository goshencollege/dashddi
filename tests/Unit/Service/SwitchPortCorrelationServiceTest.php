<?php

namespace App\Tests\Unit\Service;

use App\Entity\Host;
use App\Entity\NetworkInterface;
use App\Service\SwitchPortCorrelationService;
use PHPUnit\Framework\TestCase;

class SwitchPortCorrelationServiceTest extends TestCase
{
    private SwitchPortCorrelationService $service;

    protected function setUp(): void
    {
        $this->service = new SwitchPortCorrelationService();
    }

    private function makeIface(string $mac, ?Host $host = null, ?string $switchIp = null, ?string $switchPort = null): NetworkInterface
    {
        $iface = new NetworkInterface();
        $iface->setMacAddress($mac);
        if ($host !== null) $iface->setHost($host);
        if ($switchIp !== null) $iface->setSwitchIp($switchIp);
        if ($switchPort !== null) $iface->setSwitchPort($switchPort);
        return $iface;
    }

    public function testCleanMatchProducesNoDiscrepancies(): void
    {
        $host = new Host();
        $host->setName('desk-1');
        $iface = $this->makeIface('aa:bb:cc:dd:ee:01', $host, '10.0.0.1', '1/1/5');

        $scanPorts = [
            '1/1/5' => [
                'status' => 'up', 'speed' => '1000',
                'macs' => [['mac' => 'aa:bb:cc:dd:ee:01', 'vlan' => null]],
                'clients' => [],
                'lldp' => ['neighborName' => null, 'neighborPort' => null],
            ],
        ];
        $cachedGroups     = ['1/1/5' => [$iface]];
        $knownIfacesByMac = ['aa:bb:cc:dd:ee:01' => $iface];

        $result = $this->service->correlate($scanPorts, $cachedGroups, $knownIfacesByMac, '10.0.0.1');

        $this->assertSame([], $result['1/1/5']['discrepancies']);
        $this->assertFalse($result['1/1/5']['live']['isUplink']);
    }

    public function testMovedDeviceFlaggedOnBothPorts(): void
    {
        $host  = new Host();
        $iface = $this->makeIface('aa:bb:cc:dd:ee:02', $host, '10.0.0.1', '1/1/5');

        // Cached says port 5, but it's live on port 6.
        $scanPorts = [
            '1/1/6' => [
                'status' => 'up', 'speed' => null,
                'macs' => [['mac' => 'aa:bb:cc:dd:ee:02', 'vlan' => null]],
                'clients' => [],
                'lldp' => ['neighborName' => null, 'neighborPort' => null],
            ],
        ];
        $cachedGroups     = ['1/1/5' => [$iface]];
        $knownIfacesByMac = ['aa:bb:cc:dd:ee:02' => $iface];

        $result = $this->service->correlate($scanPorts, $cachedGroups, $knownIfacesByMac, '10.0.0.1');

        $this->assertSame('moved', $result['1/1/5']['discrepancies'][0]['type']);
        $this->assertSame('moved', $result['1/1/6']['discrepancies'][0]['type']);
    }

    public function testStaleCachedEntryNotSeenLiveAnywhere(): void
    {
        $host  = new Host();
        $iface = $this->makeIface('aa:bb:cc:dd:ee:03', $host, '10.0.0.1', '1/1/5');

        $scanPorts        = ['1/1/5' => ['status' => 'down', 'speed' => null, 'macs' => [], 'clients' => [], 'lldp' => ['neighborName' => null, 'neighborPort' => null]]];
        $cachedGroups     = ['1/1/5' => [$iface]];
        $knownIfacesByMac = ['aa:bb:cc:dd:ee:03' => $iface];

        $result = $this->service->correlate($scanPorts, $cachedGroups, $knownIfacesByMac, '10.0.0.1');

        $this->assertSame('stale', $result['1/1/5']['discrepancies'][0]['type']);
    }

    public function testUnregisteredMacFlagged(): void
    {
        $scanPorts = [
            '1/1/5' => [
                'status' => 'up', 'speed' => null,
                'macs' => [['mac' => 'ff:ff:ff:ff:ff:ff', 'vlan' => null]],
                'clients' => [],
                'lldp' => ['neighborName' => null, 'neighborPort' => null],
            ],
        ];

        $result = $this->service->correlate($scanPorts, [], [], '10.0.0.1');

        $this->assertSame('unregistered', $result['1/1/5']['discrepancies'][0]['type']);
        $this->assertFalse($result['1/1/5']['live']['macs'][0]['known']);
    }

    public function testUplinkPortSuppressesUnregisteredAndStaleNoise(): void
    {
        $macs = [];
        for ($i = 0; $i < 10; $i++) {
            $macs[] = ['mac' => sprintf('aa:bb:cc:dd:ee:%02d', $i), 'vlan' => null];
        }

        $scanPorts = [
            '1/1/48' => [
                'status' => 'up', 'speed' => '10000',
                'macs' => $macs,
                'clients' => [],
                'lldp' => ['neighborName' => 'core-switch', 'neighborPort' => '1/1/1'],
            ],
        ];

        // A cached device that's also not seen live on this uplink port — would
        // normally be "stale", but must be suppressed since this port is an uplink.
        $host  = new Host();
        $iface = $this->makeIface('bb:bb:bb:bb:bb:bb', $host, '10.0.0.1', '1/1/48');
        $cachedGroups = ['1/1/48' => [$iface]];

        $result = $this->service->correlate($scanPorts, $cachedGroups, [], '10.0.0.1');

        $this->assertTrue($result['1/1/48']['live']['isUplink']);
        $this->assertSame([], $result['1/1/48']['discrepancies']);
    }

    public function testPortsAreNaturallySorted(): void
    {
        $scanPorts = [
            '1/1/10' => ['status' => null, 'speed' => null, 'macs' => [], 'clients' => [], 'lldp' => ['neighborName' => null, 'neighborPort' => null]],
            '1/1/2'  => ['status' => null, 'speed' => null, 'macs' => [], 'clients' => [], 'lldp' => ['neighborName' => null, 'neighborPort' => null]],
        ];

        $result = $this->service->correlate($scanPorts, [], [], '10.0.0.1');

        $this->assertSame(['1/1/2', '1/1/10'], array_keys($result));
    }
}
