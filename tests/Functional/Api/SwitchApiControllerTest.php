<?php

namespace App\Tests\Functional\Api;

use App\Entity\ArubaSwitch;
use App\Entity\Host;
use App\Entity\NetworkInterface;
use App\Entity\SwitchPortLog;
use App\Enum\SwitchPortLogSource;
use App\Service\ArubaCxService;
use App\Tests\Functional\AppWebTestCase;
use Symfony\Component\HttpFoundation\Response;

class SwitchApiControllerTest extends AppWebTestCase
{
    private const SWITCH_IP = '10.254.20.1';

    /** Overrides ArubaCxService in the test container so no real SSH/REST calls happen. */
    private function installStub(array $scanPorts = []): void
    {
        $stub = new class extends ArubaCxService {
            public array $scanPorts = [];
            public function __construct() {}
            public function scanSwitch(ArubaSwitch $creds, string $ip): array
            {
                return [
                    'ports' => $this->scanPorts,
                    'raw'   => ['interfaceBrief' => '', 'portAccess' => '', 'macTable' => '', 'lldp' => ''],
                    'error' => null,
                ];
            }
            public function getPortInfo(ArubaSwitch $creds, string $ip, string $portId): array
            {
                return ['clients' => [], 'raw' => '', 'via' => 'rest', 'error' => null];
            }
        };
        $stub->scanPorts = $scanPorts;
        static::getContainer()->set(ArubaCxService::class, $stub);
    }

    private function makeIface(string $mac, ?string $switchIp = null, ?string $switchPort = null, ?\DateTimeImmutable $lastAuthAt = null): NetworkInterface
    {
        $host = (new Host())->setName('test-host-' . $mac);
        $this->em->persist($host);

        $iface = (new NetworkInterface())->setMacAddress($mac)->setHost($host);
        if ($switchIp !== null) $iface->setSwitchIp($switchIp);
        if ($switchPort !== null) $iface->setSwitchPort($switchPort);
        if ($lastAuthAt !== null) $iface->setLastAuthAt($lastAuthAt);
        $this->em->persist($iface);
        $this->em->flush();

        return $iface;
    }

    private function makeArubaSwitchCreds(): void
    {
        $this->em->persist((new ArubaSwitch())->setUsername('dash')->setPassword('secret'));
        $this->em->flush();
    }

    private function liveMacPort(string $mac, string $vlan = '44'): array
    {
        return [
            'status'  => 'up', 'speed' => '1000',
            'macs'    => [['mac' => $mac, 'vlan' => $vlan]],
            'clients' => [],
            'lldp'    => ['neighborName' => null, 'neighborPort' => null],
        ];
    }

    public function testScanUpdatesCacheForKnownMacOnNonUplinkPort(): void
    {
        $this->makeArubaSwitchCreds();
        $iface = $this->makeIface('aa:bb:cc:dd:ee:01');
        $this->installStub(['1/1/5' => $this->liveMacPort('aa:bb:cc:dd:ee:01')]);

        $data = $this->apiRequest('GET', '/api/switch/scan?switch_ip=' . self::SWITCH_IP);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertNull($data['error']);

        $this->em->clear();
        $reloaded = $this->em->find(NetworkInterface::class, $iface->getId());
        $this->assertSame(self::SWITCH_IP, $reloaded->getSwitchIp());
        $this->assertSame('1/1/5', $reloaded->getSwitchPort());
        $this->assertNotNull($reloaded->getLastAuthAt());

        $logs = $this->em->getRepository(SwitchPortLog::class)->findBy(['networkInterface' => $reloaded]);
        $this->assertCount(1, $logs);
        $this->assertSame(SwitchPortLogSource::LiveScan, $logs[0]->getSource());
        $this->assertSame('1/1/5', $logs[0]->getSwitchPort());
    }

    public function testMovedDiscrepancyStillReportedAfterCacheWrite(): void
    {
        $this->makeArubaSwitchCreds();
        $old   = new \DateTimeImmutable('-1 day');
        $iface = $this->makeIface('aa:bb:cc:dd:ee:02', self::SWITCH_IP, '1/1/5', $old);
        $this->installStub(['1/1/6' => $this->liveMacPort('aa:bb:cc:dd:ee:02')]);

        $data = $this->apiRequest('GET', '/api/switch/scan?switch_ip=' . self::SWITCH_IP);

        // Response reflects correlate()'s snapshot taken BEFORE the cache write —
        // the "moved" discrepancy must still show up even though the write below
        // brings the cache in line with the live port.
        $types = array_merge(
            array_column($data['ports']['1/1/5']['discrepancies'], 'type'),
            array_column($data['ports']['1/1/6']['discrepancies'], 'type'),
        );
        $this->assertContains('moved', $types);

        $this->em->clear();
        $reloaded = $this->em->find(NetworkInterface::class, $iface->getId());
        $this->assertSame('1/1/6', $reloaded->getSwitchPort());
        $this->assertTrue($reloaded->getLastAuthAt() > $old);
    }

    public function testUplinkPortDoesNotUpdateCache(): void
    {
        $this->makeArubaSwitchCreds();
        $iface = $this->makeIface('aa:bb:cc:dd:ee:03');

        $macs = [];
        for ($i = 0; $i < 10; $i++) {
            $macs[] = ['mac' => sprintf('bb:bb:bb:bb:bb:%02d', $i), 'vlan' => null];
        }
        $macs[] = ['mac' => 'aa:bb:cc:dd:ee:03', 'vlan' => null];

        $this->installStub([
            '1/1/48' => ['status' => 'up', 'speed' => '10000', 'macs' => $macs, 'clients' => [], 'lldp' => ['neighborName' => null, 'neighborPort' => null]],
        ]);

        $this->apiRequest('GET', '/api/switch/scan?switch_ip=' . self::SWITCH_IP);

        $this->em->clear();
        $reloaded = $this->em->find(NetworkInterface::class, $iface->getId());
        $this->assertNull($reloaded->getSwitchIp());
        $this->assertNull($reloaded->getSwitchPort());
        $this->assertCount(0, $this->em->getRepository(SwitchPortLog::class)->findBy(['networkInterface' => $reloaded]));
    }

    public function testUnknownMacDoesNotCrashOrWrite(): void
    {
        $this->makeArubaSwitchCreds();
        $this->installStub(['1/1/5' => $this->liveMacPort('ff:ff:ff:ff:ff:ff')]);

        $data = $this->apiRequest('GET', '/api/switch/scan?switch_ip=' . self::SWITCH_IP);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($data['ports']['1/1/5']['live']['macs'][0]['known']);
        $this->assertCount(0, $this->em->getRepository(SwitchPortLog::class)->findAll());
    }

    public function testResolveSwitchSucceedsFromInterfaceCacheAlone(): void
    {
        $this->makeArubaSwitchCreds();
        $this->makeIface('aa:bb:cc:dd:ee:04', self::SWITCH_IP, '1/1/7', new \DateTimeImmutable());
        $this->installStub();

        // No ClearpassAuthLog row exists at all — this is the regression test for
        // the original bug: a live-scan-only cache entry must be enough.
        $this->apiRequest('GET', '/api/switch/port-status?mac=aa:bb:cc:dd:ee:04');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testResolveSwitch404WithNoInterfaceCache(): void
    {
        $this->makeArubaSwitchCreds();
        $this->makeIface('aa:bb:cc:dd:ee:05');

        $data = $this->apiRequest('POST', '/api/switch/port-bounce', ['mac' => 'aa:bb:cc:dd:ee:05']);
        $this->assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        $this->assertSame('No switch info found for this address', $data['error']);
    }

    public function testResolveSwitch404WhenCacheOlderThanMaxAge(): void
    {
        $this->makeArubaSwitchCreds();
        // Default Switch Info Max Age is 7 days; this is well past it.
        $this->makeIface('aa:bb:cc:dd:ee:06', self::SWITCH_IP, '1/1/8', new \DateTimeImmutable('-30 days'));

        $data = $this->apiRequest('POST', '/api/switch/port-reauthenticate', ['mac' => 'aa:bb:cc:dd:ee:06']);
        $this->assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        $this->assertSame('No switch info found for this address', $data['error']);
    }
}
