<?php

namespace App\Tests\Unit\Service;

use App\Entity\Domain;
use App\Entity\Host;
use App\Entity\IpAddress;
use App\Entity\Ipv6Address;
use App\Entity\NetworkInterface;
use App\Entity\Subnet;
use App\Repository\ClearpassServerRepository;
use App\Repository\DhcpServerRepository;
use App\Repository\DnsServerRepository;
use App\Repository\NetworkInterfaceRepository;
use App\Service\PushScopeService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PushScopeServiceTest extends TestCase
{
    private PushScopeService $service;
    private NetworkInterfaceRepository $ifaceRepo;

    protected function setUp(): void
    {
        $clearpassRepo = $this->createStub(ClearpassServerRepository::class);
        $dnsRepo = $this->createStub(DnsServerRepository::class);
        $dhcpRepo = $this->createStub(DhcpServerRepository::class);
        $this->ifaceRepo = $this->createStub(NetworkInterfaceRepository::class);

        $this->service = new PushScopeService($clearpassRepo, $dnsRepo, $dhcpRepo, $this->ifaceRepo);
    }

    public function testAffectsDhcpForNetworkInterface(): void
    {
        $this->assertTrue($this->service->affectsDhcp(new NetworkInterface()));
    }

    public function testAffectsDhcpForIpAddress(): void
    {
        $this->assertTrue($this->service->affectsDhcp(new IpAddress()));
    }

    public function testAffectsDhcpForIpv6Address(): void
    {
        $this->assertTrue($this->service->affectsDhcp(new Ipv6Address()));
    }

    public function testAffectsDhcpForSubnet(): void
    {
        $this->assertTrue($this->service->affectsDhcp(new Subnet()));
    }

    public function testAffectsDhcpReturnsFalseForUnrelatedEntity(): void
    {
        $this->assertFalse($this->service->affectsDhcp(new Domain()));
    }

    public function testClearpassMacsForNetworkInterfaceWithRealMac(): void
    {
        $iface = new NetworkInterface();
        $iface->setMacAddress('aa:bb:cc:dd:ee:ff');

        $result = $this->service->clearpassMacsFor($iface);

        $this->assertSame(['aa:bb:cc:dd:ee:ff'], $result);
    }

    public function testClearpassMacsForNetworkInterfaceWithZeroMac(): void
    {
        $iface = new NetworkInterface();
        // macAddress defaults to 00:00:00:00:00:00

        $result = $this->service->clearpassMacsFor($iface);

        $this->assertSame([], $result);
    }

    public function testClearpassMacsForIpAddressLooksUpMac(): void
    {
        $ip = new IpAddress();
        $this->ifaceRepo->method('findMacByIpAddress')->willReturn('11:22:33:44:55:66');

        $result = $this->service->clearpassMacsFor($ip);

        $this->assertSame(['11:22:33:44:55:66'], $result);
    }

    public function testClearpassMacsForIpAddressReturnsEmptyWhenNoInterface(): void
    {
        $ip = new IpAddress();
        $this->ifaceRepo->method('findMacByIpAddress')->willReturn(null);

        $result = $this->service->clearpassMacsFor($ip);

        $this->assertSame([], $result);
    }

    public function testClearpassMacsForIpv6AddressLooksUpMac(): void
    {
        $ip = new Ipv6Address();
        $this->ifaceRepo->method('findMacByIpv6Address')->willReturn('aa:aa:bb:bb:cc:cc');

        $result = $this->service->clearpassMacsFor($ip);

        $this->assertSame(['aa:aa:bb:bb:cc:cc'], $result);
    }

    public function testClearpassMacsForUnrelatedEntityReturnsEmpty(): void
    {
        $this->assertSame([], $this->service->clearpassMacsFor(new Domain()));
    }

    public function testClearpassMacsForHostReturnsAllActiveInterfaceMacs(): void
    {
        $host = new Host();

        $iface1 = new NetworkInterface();
        $iface1->setMacAddress('aa:bb:cc:dd:ee:01');

        $iface2 = new NetworkInterface();
        $iface2->setMacAddress('aa:bb:cc:dd:ee:02');

        $host->addInterface($iface1);
        $host->addInterface($iface2);

        $this->assertSame(['aa:bb:cc:dd:ee:01', 'aa:bb:cc:dd:ee:02'], $this->service->clearpassMacsForHost($host));
    }

    public function testClearpassMacsForHostSkipsDeletedInterfaces(): void
    {
        $host = new Host();

        $active = new NetworkInterface();
        $active->setMacAddress('aa:bb:cc:dd:ee:01');

        $deleted = new NetworkInterface();
        $deleted->setMacAddress('aa:bb:cc:dd:ee:02');
        $deleted->softDelete();

        $host->addInterface($active);
        $host->addInterface($deleted);

        $this->assertSame(['aa:bb:cc:dd:ee:01'], $this->service->clearpassMacsForHost($host));
    }

    public function testClearpassMacsForHostSkipsZeroMacInterfaces(): void
    {
        $host = new Host();

        $real = new NetworkInterface();
        $real->setMacAddress('aa:bb:cc:dd:ee:01');

        $placeholder = new NetworkInterface();
        // macAddress defaults to 00:00:00:00:00:00

        $host->addInterface($real);
        $host->addInterface($placeholder);

        $this->assertSame(['aa:bb:cc:dd:ee:01'], $this->service->clearpassMacsForHost($host));
    }

    public function testClearpassMacsForHostWithNoInterfacesReturnsEmpty(): void
    {
        $this->assertSame([], $this->service->clearpassMacsForHost(new Host()));
    }
}
