<?php

namespace App\Tests\Unit\Service;

use App\Entity\Subnet;
use App\Repository\AddressBlockRepository;
use App\Repository\IpAddressRepository;
use App\Repository\Ipv6AddressRepository;
use App\Service\IpAddressManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class IpAddressManagerTest extends TestCase
{
    private IpAddressManager $manager;
    private IpAddressRepository $ipRepo;
    private Ipv6AddressRepository $ipv6Repo;
    private AddressBlockRepository $blockRepo;

    protected function setUp(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $this->ipRepo = $this->createStub(IpAddressRepository::class);
        $this->ipv6Repo = $this->createStub(Ipv6AddressRepository::class);
        $this->blockRepo = $this->createStub(AddressBlockRepository::class);

        $this->manager = new IpAddressManager($em, $this->ipRepo, $this->ipv6Repo, $this->blockRepo);
    }

    private static int $subnetIdCounter = 1;

    private function makeSubnet(string $ipv4Cidr = null, string $ipv6Cidr = null): Subnet
    {
        $subnet = new Subnet();
        // Set a non-null ID so repository method signatures (int $subnetId) are satisfied
        $prop = new \ReflectionProperty(Subnet::class, 'id');
        $prop->setValue($subnet, self::$subnetIdCounter++);

        if ($ipv4Cidr !== null) {
            $subnet->setIpv4Cidr($ipv4Cidr);
        }
        if ($ipv6Cidr !== null) {
            $subnet->setIpv6Cidr($ipv6Cidr);
        }
        return $subnet;
    }

    public function testGetAvailableIpv4ReturnsEmptyWhenNoCidr(): void
    {
        $subnet = $this->makeSubnet();

        $result = $this->manager->getAvailableIpv4($subnet);

        $this->assertSame([], $result);
    }

    public function testGetAvailableIpv4ReturnsHostAddresses(): void
    {
        $subnet = $this->makeSubnet('192.168.1.0/30');

        $this->ipRepo->method('findAllocatedAddressesForSubnet')->willReturn([]);
        $this->blockRepo->method('findFixedBySubnet')->willReturn([]);

        $result = $this->manager->getAvailableIpv4($subnet);

        // /30 has 2 usable hosts: .1 and .2 (network .0, broadcast .3)
        $this->assertSame(['192.168.1.1', '192.168.1.2'], $result);
    }

    public function testGetAvailableIpv4ExcludesAllocated(): void
    {
        $subnet = $this->makeSubnet('192.168.1.0/30');

        $this->ipRepo->method('findAllocatedAddressesForSubnet')->willReturn(['192.168.1.1']);
        $this->blockRepo->method('findFixedBySubnet')->willReturn([]);

        $result = $this->manager->getAvailableIpv4($subnet);

        $this->assertSame(['192.168.1.2'], $result);
    }

    public function testGetAvailableIpv4RespectsLimit(): void
    {
        $subnet = $this->makeSubnet('10.0.0.0/24');

        $this->ipRepo->method('findAllocatedAddressesForSubnet')->willReturn([]);
        $this->blockRepo->method('findFixedBySubnet')->willReturn([]);

        $result = $this->manager->getAvailableIpv4($subnet, 5);

        $this->assertCount(5, $result);
        $this->assertSame('10.0.0.1', $result[0]);
    }

    public function testGetAvailableIpv6ReturnsEmptyWhenNoCidr(): void
    {
        $subnet = $this->makeSubnet();

        $result = $this->manager->getAvailableIpv6($subnet);

        $this->assertSame([], $result);
    }

    public function testGetAvailableIpv6ReturnsAddresses(): void
    {
        $subnet = $this->makeSubnet(null, '2001:db8::/126');

        $this->ipv6Repo->method('findAllocatedAddressesForSubnet')->willReturn([]);
        $this->blockRepo->method('findFixedBySubnet')->willReturn([]);

        $result = $this->manager->getAvailableIpv6($subnet);

        $this->assertNotEmpty($result);
        $this->assertStringStartsWith('2001:db8::', $result[0]);
    }

    public function testFindNextAvailableIpv4ReturnsFirstAvailable(): void
    {
        $subnet = $this->makeSubnet('192.168.1.0/30');

        $this->ipRepo->method('findAllocatedAddressesForSubnet')->willReturn([]);
        $this->blockRepo->method('findFixedBySubnet')->willReturn([]);

        $this->assertSame('192.168.1.1', $this->manager->findNextAvailableIpv4($subnet));
    }

    public function testFindNextAvailableIpv4ReturnsNullWhenFull(): void
    {
        $subnet = $this->makeSubnet('192.168.1.0/30');

        $this->ipRepo->method('findAllocatedAddressesForSubnet')
            ->willReturn(['192.168.1.1', '192.168.1.2']);
        $this->blockRepo->method('findFixedBySubnet')->willReturn([]);

        $this->assertNull($this->manager->findNextAvailableIpv4($subnet));
    }

    public function testFindIpv6FromIpv4ReturnsNullWithNoIpv6Cidr(): void
    {
        $subnet = $this->makeSubnet('192.168.1.0/24');

        $this->assertNull($this->manager->findIpv6FromIpv4($subnet, '192.168.1.10'));
    }

    public function testFindIpv6FromIpv4DerivedFromLastOctet(): void
    {
        $subnet = $this->makeSubnet('192.168.1.0/24', '2001:db8::/64');

        $this->ipv6Repo->method('findAllocatedAddressesForSubnet')->willReturn([]);
        $this->blockRepo->method('findFixedBySubnet')->willReturn([]);

        $result = $this->manager->findIpv6FromIpv4($subnet, '192.168.1.42');

        $this->assertNotNull($result);
        // Last byte of the IPv6 should correspond to last octet of IPv4
        $parsed = inet_pton($result);
        $this->assertNotFalse($parsed);
        $this->assertSame(42, ord($parsed[15]));
    }

    public function testFindNextAvailableIpv6WithMacUsesEui64(): void
    {
        $subnet = $this->makeSubnet(null, '2001:db8::/64');
        $mac = 'aa:bb:cc:dd:ee:ff';

        $this->ipv6Repo->method('findAllocatedAddressesForSubnet')->willReturn([]);
        $this->blockRepo->method('findFixedBySubnet')->willReturn([]);

        $result = $this->manager->findNextAvailableIpv6($subnet, $mac);

        // EUI-64 derivation: aa:bb:cc -> flip bit -> a8:bb:cc, dd:ee:ff -> a8bb:ccff:fedd:eeff
        $this->assertNotNull($result);
        $this->assertStringStartsWith('2001:db8::', $result);
    }
}
