<?php

namespace App\Tests\Unit\Service;

use App\Entity\AddressBlock;
use App\Entity\Domain;
use App\Entity\DomainRecord;
use App\Entity\Host;
use App\Entity\IpAddress;
use App\Entity\Ipv6Address;
use App\Entity\NetworkInterface;
use App\Entity\Subnet;
use App\Enum\BlockType;
use App\Repository\SubnetRepository;
use App\Service\DhcpConfigGenerator;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

class DhcpConfigGeneratorTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeGenerator(array $subnets): DhcpConfigGenerator
    {
        $repo = $this->createStub(SubnetRepository::class);
        $repo->method('findAll')->willReturn($subnets);
        return new DhcpConfigGenerator($repo);
    }

    private function setCollection(object $entity, string $property, array $items): void
    {
        (new \ReflectionProperty($entity, $property))
            ->setValue($entity, new ArrayCollection($items));
    }

    private function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }

    private function makeSubnet(int $id, ?string $cidr4 = '10.0.0.0/24', ?string $cidr6 = null): Subnet
    {
        $subnet = (new Subnet())->setName('test')->setIpv4Cidr($cidr4)->setIpv6Cidr($cidr6);
        $this->setId($subnet, $id);
        return $subnet;
    }

    private function makeIface(string $mac, string $ip): NetworkInterface
    {
        $addr = (new IpAddress())->setAddress($ip);
        return (new NetworkInterface())
            ->setMacAddress($mac)
            ->setIpAddress($addr);
    }

    private function makeIfaceWithHostname(string $mac, string $ip, string $hostname, string $domain): NetworkInterface
    {
        $iface  = $this->makeIface($mac, $ip);
        $record = (new DomainRecord())
            ->setHostname($hostname)
            ->setDomain((new Domain())->setName($domain));
        $this->setCollection($iface, 'domainRecords', [$record]);
        return $iface;
    }

    // ── IPv4 tests ────────────────────────────────────────────────────────────

    public function testReservationHostnameHasTrailingDot(): void
    {
        $iface  = $this->makeIfaceWithHostname('aa:bb:cc:dd:ee:ff', '10.0.0.5', 'web', 'example.com');
        $subnet = $this->makeSubnet(1);
        $this->setCollection($subnet, 'interfaces', [$iface]);
        $this->setCollection($subnet, 'addressBlocks', []);

        $result = $this->makeGenerator([$subnet])->generateDhcp4Config();

        $hostname = $result[0]['reservations'][0]['hostname'];
        $this->assertSame('web.example.com.', $hostname);
    }

    public function testHostnameAlreadyEndingWithDotNotDoubled(): void
    {
        // getPrimaryName() returns the FQDN from getFullyQualifiedHostname(); ensure
        // rtrim+append is idempotent if somehow a trailing dot slips through upstream.
        $iface  = $this->makeIface('aa:bb:cc:dd:ee:01', '10.0.0.6');
        // Manually set a domain record whose getFullyQualifiedHostname() would include a
        // trailing dot (edge case); use a DomainRecord with domain name already ending in dot.
        $record = (new DomainRecord())
            ->setHostname('host')
            ->setDomain((new Domain())->setName('example.com.'));
        $this->setCollection($iface, 'domainRecords', [$record]);
        $subnet = $this->makeSubnet(2);
        $this->setCollection($subnet, 'interfaces', [$iface]);
        $this->setCollection($subnet, 'addressBlocks', []);

        $result   = $this->makeGenerator([$subnet])->generateDhcp4Config();
        $hostname = $result[0]['reservations'][0]['hostname'];

        // Should be exactly one trailing dot
        $this->assertSame('host.example.com.', $hostname);
        $this->assertStringEndsNotWith('..', $hostname);
    }

    public function testHostWithNoDnsRecordOmitsHostnameFromReservation(): void
    {
        $iface  = $this->makeIface('11:22:33:44:55:66', '10.0.0.7');
        $subnet = $this->makeSubnet(3);
        $this->setCollection($subnet, 'interfaces', [$iface]);
        $this->setCollection($subnet, 'addressBlocks', []);

        $result      = $this->makeGenerator([$subnet])->generateDhcp4Config();
        $reservation = $result[0]['reservations'][0];

        $this->assertArrayNotHasKey('hostname', $reservation);
        $this->assertSame('10.0.0.7', $reservation['ip-address']);
    }

    public function testSubnetWithDdnsDomainEmitsDdnsSettings(): void
    {
        $domain = (new Domain())->setName('dyn.example.com');
        $subnet = $this->makeSubnet(4)->setDdnsDomain($domain);
        $this->setCollection($subnet, 'interfaces', []);
        $this->setCollection($subnet, 'addressBlocks', []);

        $result = $this->makeGenerator([$subnet])->generateDhcp4Config();
        $block  = $result[0];

        $this->assertTrue($block['ddns-send-updates']);
        $this->assertTrue($block['ddns-update-on-renew']);
        $this->assertArrayNotHasKey('ddns-qualifying-suffix', $block);
        $this->assertSame('never', $block['ddns-replace-client-name']);
    }

    public function testSubnetWithoutDdnsDomainOmitsDdnsSettings(): void
    {
        $subnet = $this->makeSubnet(5);
        $this->setCollection($subnet, 'interfaces', []);
        $this->setCollection($subnet, 'addressBlocks', []);

        $result = $this->makeGenerator([$subnet])->generateDhcp4Config();
        $block  = $result[0];

        $this->assertArrayNotHasKey('ddns-send-updates', $block);
        $this->assertArrayNotHasKey('ddns-qualifying-suffix', $block);
    }

    public function testDeletedInterfaceSkippedInReservations(): void
    {
        $iface = $this->makeIface('de:ad:be:ef:00:01', '10.0.0.8');
        (new \ReflectionProperty($iface, 'deletedAt'))->setValue($iface, new \DateTimeImmutable());
        $subnet = $this->makeSubnet(6);
        $this->setCollection($subnet, 'interfaces', [$iface]);
        $this->setCollection($subnet, 'addressBlocks', []);

        $result = $this->makeGenerator([$subnet])->generateDhcp4Config();

        $this->assertArrayNotHasKey('reservations', $result[0]);
    }

    public function testZeroMacInterfaceSkippedInReservations(): void
    {
        $addr   = (new IpAddress())->setAddress('10.0.0.9');
        $iface  = (new NetworkInterface())->setMacAddress('00:00:00:00:00:00')->setIpAddress($addr);
        $subnet = $this->makeSubnet(7);
        $this->setCollection($subnet, 'interfaces', [$iface]);
        $this->setCollection($subnet, 'addressBlocks', []);

        $result = $this->makeGenerator([$subnet])->generateDhcp4Config();

        $this->assertArrayNotHasKey('reservations', $result[0]);
    }

    public function testDynamicPoolIncludedInSubnet(): void
    {
        $block = (new AddressBlock())
            ->setType(BlockType::Dynamic)
            ->setStartIp('10.0.0.100')
            ->setEndIp('10.0.0.200');
        $subnet = $this->makeSubnet(8);
        $this->setCollection($subnet, 'interfaces', []);
        $this->setCollection($subnet, 'addressBlocks', [$block]);

        $result = $this->makeGenerator([$subnet])->generateDhcp4Config();

        $this->assertSame([['pool' => '10.0.0.100 - 10.0.0.200']], $result[0]['pools']);
    }

    public function testDdnsSubnetWithDdnsHostnameDoesNotSuppressDdnsOnReservation(): void
    {
        $ddnsDomain = (new Domain())->setName('dyn.goshen.edu')->setDdnsEnabled(true);
        $iface      = $this->makeIface('aa:bb:cc:00:11:33', '10.0.0.11');
        $record     = (new DomainRecord())->setHostname('myhost')->setDomain($ddnsDomain);
        $this->setCollection($iface, 'domainRecords', [$record]);
        $subnet = $this->makeSubnet(11)->setDdnsDomain($ddnsDomain);
        $this->setCollection($subnet, 'interfaces', [$iface]);
        $this->setCollection($subnet, 'addressBlocks', []);

        $reservation = $this->makeGenerator([$subnet])->generateDhcp4Config()[0]['reservations'][0];

        $this->assertArrayNotHasKey('ddns-send-updates', $reservation);
    }

    public function testNonDdnsSubnetDoesNotSuppressDdnsOnReservation(): void
    {
        $iface  = $this->makeIfaceWithHostname('aa:bb:cc:00:11:44', '10.0.0.12', 'statichost', 'goshen.edu');
        $subnet = $this->makeSubnet(12);
        $this->setCollection($subnet, 'interfaces', [$iface]);
        $this->setCollection($subnet, 'addressBlocks', []);

        $reservation = $this->makeGenerator([$subnet])->generateDhcp4Config()[0]['reservations'][0];

        $this->assertArrayNotHasKey('ddns-send-updates', $reservation);
    }

    // ── IPv6 tests ────────────────────────────────────────────────────────────

    public function testIpv6ReservationHostnameHasTrailingDot(): void
    {
        $addr   = (new Ipv6Address())->setAddress('2001:db8::1');
        $iface  = (new NetworkInterface())
            ->setMacAddress('aa:bb:cc:dd:ee:01')
            ->setIpv6Address($addr);
        $record = (new DomainRecord())
            ->setHostname('host6')
            ->setDomain((new Domain())->setName('example.com'));
        $this->setCollection($iface, 'domainRecords', [$record]);

        $subnet = (new Subnet())->setName('test')->setIpv6Cidr('2001:db8::/32');
        $this->setId($subnet, 9);
        $this->setCollection($subnet, 'interfaces', [$iface]);
        $this->setCollection($subnet, 'addressBlocks', []);

        $result   = $this->makeGenerator([$subnet])->generateDhcp6Config();
        $hostname = $result[0]['reservations'][0]['hostname'];

        $this->assertSame('host6.example.com.', $hostname);
    }

    public function testIpv6ReservationUsesDuidWhenHostDuidIsSet(): void
    {
        $addr  = (new Ipv6Address())->setAddress('2001:db8::2');
        $host  = (new Host())->setName('duid-host')->setDuid('00020000ab11cc5702f3da97b768');
        $iface = (new NetworkInterface())
            ->setMacAddress('aa:bb:cc:dd:ee:02')
            ->setIpv6Address($addr)
            ->setHost($host);

        $subnet = (new Subnet())->setName('test')->setIpv6Cidr('2001:db8::/32');
        $this->setId($subnet, 20);
        $this->setCollection($subnet, 'interfaces', [$iface]);
        $this->setCollection($subnet, 'addressBlocks', []);

        $reservation = $this->makeGenerator([$subnet])->generateDhcp6Config()[0]['reservations'][0];

        $this->assertSame('00:02:00:00:ab:11:cc:57:02:f3:da:97:b7:68', $reservation['duid']);
        $this->assertArrayNotHasKey('hw-address', $reservation);
    }

    public function testIpv6ReservationFallsBackToMacWhenHostHasNoDuid(): void
    {
        $addr  = (new Ipv6Address())->setAddress('2001:db8::3');
        $host  = (new Host())->setName('no-duid-host');
        $iface = (new NetworkInterface())
            ->setMacAddress('aa:bb:cc:dd:ee:03')
            ->setIpv6Address($addr)
            ->setHost($host);

        $subnet = (new Subnet())->setName('test')->setIpv6Cidr('2001:db8::/32');
        $this->setId($subnet, 21);
        $this->setCollection($subnet, 'interfaces', [$iface]);
        $this->setCollection($subnet, 'addressBlocks', []);

        $reservation = $this->makeGenerator([$subnet])->generateDhcp6Config()[0]['reservations'][0];

        $this->assertSame('aa:bb:cc:dd:ee:03', $reservation['hw-address']);
        $this->assertArrayNotHasKey('duid', $reservation);
    }

    public function testIpv6ReservationFallsBackToMacWhenInterfaceHasNoHost(): void
    {
        $addr  = (new Ipv6Address())->setAddress('2001:db8::4');
        $iface = (new NetworkInterface())->setMacAddress('aa:bb:cc:dd:ee:04')->setIpv6Address($addr);

        $subnet = (new Subnet())->setName('test')->setIpv6Cidr('2001:db8::/32');
        $this->setId($subnet, 22);
        $this->setCollection($subnet, 'interfaces', [$iface]);
        $this->setCollection($subnet, 'addressBlocks', []);

        $reservation = $this->makeGenerator([$subnet])->generateDhcp6Config()[0]['reservations'][0];

        $this->assertSame('aa:bb:cc:dd:ee:04', $reservation['hw-address']);
    }

    public function testIpv6ReservationSkippedWhenNoDuidAndZeroMac(): void
    {
        $addr  = (new Ipv6Address())->setAddress('2001:db8::5');
        $iface = (new NetworkInterface())->setMacAddress('00:00:00:00:00:00')->setIpv6Address($addr);

        $subnet = (new Subnet())->setName('test')->setIpv6Cidr('2001:db8::/32');
        $this->setId($subnet, 23);
        $this->setCollection($subnet, 'interfaces', [$iface]);
        $this->setCollection($subnet, 'addressBlocks', []);

        $result = $this->makeGenerator([$subnet])->generateDhcp6Config();

        $this->assertArrayNotHasKey('reservations', $result[0]);
    }

    public function testIpv6ReservationIncludedViaDuidEvenWhenMacIsZero(): void
    {
        $addr  = (new Ipv6Address())->setAddress('2001:db8::6');
        $host  = (new Host())->setName('duid-only-host')->setDuid('00020000ab11cc5702f3da97b768');
        $iface = (new NetworkInterface())
            ->setMacAddress('00:00:00:00:00:00')
            ->setIpv6Address($addr)
            ->setHost($host);

        $subnet = (new Subnet())->setName('test')->setIpv6Cidr('2001:db8::/32');
        $this->setId($subnet, 24);
        $this->setCollection($subnet, 'interfaces', [$iface]);
        $this->setCollection($subnet, 'addressBlocks', []);

        $reservation = $this->makeGenerator([$subnet])->generateDhcp6Config()[0]['reservations'][0];

        $this->assertSame('00:02:00:00:ab:11:cc:57:02:f3:da:97:b7:68', $reservation['duid']);
    }

    // ── IPv6 global reservation tests ────────────────────────────────────────

    public function testGlobalIpv6ReservationUsesDuidWhenHostDuidIsSet(): void
    {
        $ddnsDomain = (new Domain())->setName('dyn.example.com')->setDdnsEnabled(true);
        $host       = (new Host())->setName('global-duid-host')->setDuid('00020000ab11cc5702f3da97b768');
        $iface      = (new NetworkInterface())->setMacAddress('aa:bb:cc:dd:ee:05')->setHost($host);
        $record     = (new DomainRecord())->setHostname('globalhost6')->setDomain($ddnsDomain);
        $this->setCollection($iface, 'domainRecords', [$record]);

        $subnet = (new Subnet())->setName('test')->setIpv6Cidr('2001:db8::/32');
        $this->setId($subnet, 25);
        $this->setCollection($subnet, 'interfaces', [$iface]);
        $this->setCollection($subnet, 'addressBlocks', []);

        $reservations = $this->makeGenerator([$subnet])->generateGlobalReservations6Config();

        $this->assertCount(1, $reservations);
        $this->assertSame('00:02:00:00:ab:11:cc:57:02:f3:da:97:b7:68', $reservations[0]['duid']);
        $this->assertArrayNotHasKey('hw-address', $reservations[0]);
    }

    public function testGlobalIpv6ReservationFallsBackToMacWhenNoDuid(): void
    {
        $ddnsDomain = (new Domain())->setName('dyn.example.com')->setDdnsEnabled(true);
        $iface      = (new NetworkInterface())->setMacAddress('aa:bb:cc:dd:ee:06');
        $record     = (new DomainRecord())->setHostname('globalhost6b')->setDomain($ddnsDomain);
        $this->setCollection($iface, 'domainRecords', [$record]);

        $subnet = (new Subnet())->setName('test')->setIpv6Cidr('2001:db8::/32');
        $this->setId($subnet, 26);
        $this->setCollection($subnet, 'interfaces', [$iface]);
        $this->setCollection($subnet, 'addressBlocks', []);

        $reservations = $this->makeGenerator([$subnet])->generateGlobalReservations6Config();

        $this->assertCount(1, $reservations);
        $this->assertSame('aa:bb:cc:dd:ee:06', $reservations[0]['hw-address']);
        $this->assertArrayNotHasKey('duid', $reservations[0]);
    }
}
