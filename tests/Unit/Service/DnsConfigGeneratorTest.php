<?php

namespace App\Tests\Unit\Service;

use App\Entity\Domain;
use App\Entity\DnsServer;
use App\Entity\DnsView;
use App\Entity\IpAddress;
use App\Entity\NetworkInterface;
use App\Entity\DomainRecord;
use App\Entity\Subnet;
use App\Entity\SubnetRecord;
use App\Enum\RecordType;
use App\Enum\TsigAlgorithm;
use App\Repository\DnsAclRepository;
use App\Repository\DnssecPolicyRepository;
use App\Repository\DomainRepository;
use App\Repository\SubnetRepository;
use App\Service\DnsConfigGenerator;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use IPLib\Factory as IpLibFactory;
use PHPUnit\Framework\TestCase;

class DnsConfigGeneratorTest extends TestCase
{
    private DnsConfigGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new DnsConfigGenerator(
            $this->createStub(DomainRepository::class),
            $this->createStub(SubnetRepository::class),
            $this->createStub(DnssecPolicyRepository::class),
            $this->createStub(DnsAclRepository::class),
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeSubnet(string $cidr = '10.0.0.0/24'): Subnet
    {
        $subnet = new Subnet();
        $subnet->setName('Test')
               ->setIpv4Cidr($cidr)
               ->setSoaNameserver('ns1.example.com')
               ->setSoaEmail('hostmaster@example.com');
        return $subnet;
    }

    private function makeView(int $id, string $name = 'internal'): DnsView
    {
        $view = (new DnsView())->setName($name);
        (new \ReflectionProperty(DnsView::class, 'id'))->setValue($view, $id);
        return $view;
    }

    private function attachRecords(Subnet $subnet, SubnetRecord ...$records): void
    {
        (new \ReflectionProperty(Subnet::class, 'records'))
            ->setValue($subnet, new ArrayCollection($records));
    }

    // ── Reverse zone manual records ───────────────────────────────────────────

    public function testManualRecordAppearsWithNullView(): void
    {
        $subnet = $this->makeSubnet();
        $record = (new SubnetRecord())->setHostname('@')->setType(RecordType::NS)->setValue('ns2.example.com.');
        $this->attachRecords($subnet, $record);

        $output = $this->generator->generateReverseZoneFile($subnet, '10.0.0.0/24', null);

        $this->assertStringContainsString('@ IN NS ns2.example.com.', $output);
        $this->assertStringContainsString('; Manual records', $output);
    }

    public function testManualRecordAppearsWhenViewMatches(): void
    {
        $view   = $this->makeView(1);
        $subnet = $this->makeSubnet();
        $record = (new SubnetRecord())->setHostname('@')->setType(RecordType::NS)->setValue('ns2.example.com.');
        $record->addView($view);
        $this->attachRecords($subnet, $record);

        $output = $this->generator->generateReverseZoneFile($subnet, '10.0.0.0/24', $view);

        $this->assertStringContainsString('@ IN NS ns2.example.com.', $output);
    }

    public function testManualRecordHiddenWhenViewDoesNotMatch(): void
    {
        $view1  = $this->makeView(1, 'internal');
        $view2  = $this->makeView(2, 'external');
        $subnet = $this->makeSubnet();
        $record = (new SubnetRecord())->setHostname('@')->setType(RecordType::NS)->setValue('ns2.example.com.');
        $record->addView($view1); // only in view1
        $this->attachRecords($subnet, $record);

        $output = $this->generator->generateReverseZoneFile($subnet, '10.0.0.0/24', $view2);

        $this->assertStringNotContainsString('@ IN NS ns2.example.com.', $output);
        $this->assertStringNotContainsString('; Manual records', $output);
    }

    public function testManualRecordIncludesTtlWhenSet(): void
    {
        $subnet = $this->makeSubnet();
        $record = (new SubnetRecord())->setHostname('@')->setType(RecordType::NS)->setValue('ns2.example.com.')->setTtl(600);
        $this->attachRecords($subnet, $record);

        $output = $this->generator->generateReverseZoneFile($subnet, '10.0.0.0/24', null);

        $this->assertStringContainsString('@ 600 IN NS ns2.example.com.', $output);
    }

    public function testMultipleManualRecordsAllAppear(): void
    {
        $subnet  = $this->makeSubnet();
        $record1 = (new SubnetRecord())->setHostname('@')->setType(RecordType::NS)->setValue('ns1.example.com.');
        $record2 = (new SubnetRecord())->setHostname('@')->setType(RecordType::NS)->setValue('ns2.example.com.');
        $this->attachRecords($subnet, $record1, $record2);

        $output = $this->generator->generateReverseZoneFile($subnet, '10.0.0.0/24', null);

        $this->assertStringContainsString('@ IN NS ns1.example.com.', $output);
        $this->assertStringContainsString('@ IN NS ns2.example.com.', $output);
    }

    public function testManualRecordsAppearAfterPtrSection(): void
    {
        $subnet = $this->makeSubnet();
        $record = (new SubnetRecord())->setHostname('@')->setType(RecordType::NS)->setValue('ns2.example.com.');
        $this->attachRecords($subnet, $record);

        $output = $this->generator->generateReverseZoneFile($subnet, '10.0.0.0/24', null);

        // SOA block comes first, manual records comment comes after
        $soaPos    = strpos($output, 'IN SOA');
        $recordPos = strpos($output, '; Manual records');
        $this->assertNotFalse($soaPos);
        $this->assertNotFalse($recordPos);
        $this->assertGreaterThan($soaPos, $recordPos);
    }

    public function testNoManualRecordsSectionOmittedWhenEmpty(): void
    {
        $subnet = $this->makeSubnet();
        $this->attachRecords($subnet); // no records

        $output = $this->generator->generateReverseZoneFile($subnet, '10.0.0.0/24', null);

        $this->assertStringNotContainsString('; Manual records', $output);
    }

    public function testRecordWithNoViewsOnlyAppearsWithNullFilter(): void
    {
        $view   = $this->makeView(1);
        $subnet = $this->makeSubnet();
        $record = (new SubnetRecord())->setHostname('@')->setType(RecordType::NS)->setValue('ns2.example.com.');
        // no views added to record
        $this->attachRecords($subnet, $record);

        // null view = no filter → record appears
        $this->assertStringContainsString(
            '@ IN NS ns2.example.com.',
            $this->generator->generateReverseZoneFile($subnet, '10.0.0.0/24', null)
        );
        // specific view filter → record does NOT appear (no views assigned)
        $this->assertStringNotContainsString(
            '@ IN NS ns2.example.com.',
            $this->generator->generateReverseZoneFile($subnet, '10.0.0.0/24', $view)
        );
    }

    // ── generateViewsConf DDNS ────────────────────────────────────────────────

    public function testTsigKeyBlockEmittedWhenDdnsConfigured(): void
    {
        $server = $this->makeServerWithDdns('bind-primary', TsigAlgorithm::HmacSha256, 'secret==');
        $gen    = $this->makeViewsConfGenerator($server, [], []);

        $output = $gen->generateViewsConf($server);

        $this->assertStringContainsString('key "ddns-bind-primary" {', $output);
        $this->assertStringContainsString('algorithm hmac-sha256;', $output);
        $this->assertStringContainsString('secret "secret==";', $output);
    }

    public function testTsigKeyBlockOmittedWhenNoDdns(): void
    {
        $server = (new DnsServer())->setName('ns1')->setHostname('10.0.0.1');
        $gen    = $this->makeViewsConfGenerator($server, [], []);

        $output = $gen->generateViewsConf($server);

        $this->assertStringNotContainsString('key "', $output);
    }

    public function testAllowUpdateAddedToForwardZoneWhenDdnsEnabled(): void
    {
        $server = $this->makeServerWithDdns('ns1', TsigAlgorithm::HmacSha256, 'secret');
        $view   = $this->makeView(1);
        $server->addView($view);

        $domain = (new Domain())
            ->setName('example.com')
            ->setDdnsEnabled(true)
            ->setDdnsDnsServer($server);

        $gen    = $this->makeViewsConfGenerator($server, [$domain], []);
        $output = $gen->generateViewsConf($server);

        $this->assertStringContainsString('allow-update { key "ddns-ns1"; };', $output);
    }

    public function testAllowUpdateOmittedWhenDomainDdnsDisabled(): void
    {
        $server = $this->makeServerWithDdns('ns1', TsigAlgorithm::HmacSha256, 'secret');
        $view   = $this->makeView(1);
        $server->addView($view);

        $domain = (new Domain())
            ->setName('example.com')
            ->setDdnsEnabled(false)
            ->setDdnsDnsServer($server);

        $gen    = $this->makeViewsConfGenerator($server, [$domain], []);
        $output = $gen->generateViewsConf($server);

        $this->assertStringNotContainsString('allow-update', $output);
    }

    public function testAllowUpdateAddedToReverseZoneWhenDdnsEnabled(): void
    {
        $server = $this->makeServerWithDdns('ns1', TsigAlgorithm::HmacSha256, 'secret');
        $view   = $this->makeView(1);
        $server->addView($view);

        $domain = (new Domain())
            ->setName('example.com')
            ->setDdnsEnabled(true)
            ->setDdnsDnsServer($server);
        $subnet = (new Subnet())
            ->setIpv4Cidr('192.168.1.0/24')
            ->setDdnsDomain($domain);

        $gen    = $this->makeViewsConfGenerator($server, [], [$subnet]);
        $output = $gen->generateViewsConf($server);

        $this->assertStringContainsString('1.168.192.in-addr.arpa', $output);
        $this->assertStringContainsString('allow-update { key "ddns-ns1"; };', $output);
    }

    // ── generateViewsConf secondary server ───────────────────────────────────

    public function testSecondaryConfEmitsNoViewBlock(): void
    {
        $server = (new DnsServer())
            ->setName('ns2')
            ->setHostname('10.0.0.2')
            ->setServerType('secondary')
            ->setPrimaryHostname('10.0.0.1');
        $view = $this->makeView(1);
        $server->addView($view);

        $domain = (new Domain())->setName('example.com');
        $gen    = $this->makeViewsConfGenerator($server, [$domain], []);
        $output = $gen->generateViewsConf($server);

        $this->assertStringNotContainsString('view "', $output);
        $this->assertStringContainsString('zone "example.com" IN {', $output);
        $this->assertStringContainsString('    type secondary;', $output);
        $this->assertStringContainsString('    primaries { 10.0.0.1; };', $output);
    }

    public function testSecondaryConfEmitsNoAclsOrDnssecPolicies(): void
    {
        $server = (new DnsServer())
            ->setName('ns2')
            ->setHostname('10.0.0.2')
            ->setServerType('secondary');
        $view = $this->makeView(1);
        $server->addView($view);

        $gen    = $this->makeViewsConfGenerator($server, [], []);
        $output = $gen->generateViewsConf($server);

        $this->assertStringNotContainsString('acl ', $output);
        $this->assertStringNotContainsString('dnssec-policy ', $output);
    }

    public function testSecondaryConfEmitsNoTsigKeyBlock(): void
    {
        $server = (new DnsServer())
            ->setName('ns2')
            ->setHostname('10.0.0.2')
            ->setServerType('secondary')
            ->setDdnsAlgorithm(TsigAlgorithm::HmacSha256)
            ->setDdnsSecret('secret==');
        $view = $this->makeView(1);
        $server->addView($view);

        $gen    = $this->makeViewsConfGenerator($server, [], []);
        $output = $gen->generateViewsConf($server);

        $this->assertStringNotContainsString('key "', $output);
    }

    public function testSecondaryConfIncludesReverseZone(): void
    {
        $server = (new DnsServer())
            ->setName('ns2')
            ->setHostname('10.0.0.2')
            ->setServerType('secondary')
            ->setPrimaryHostname('10.0.0.1');
        $view = $this->makeView(1);
        $server->addView($view);

        $subnet = (new Subnet())->setIpv4Cidr('192.168.1.0/24');
        $gen    = $this->makeViewsConfGenerator($server, [], [$subnet]);
        $output = $gen->generateViewsConf($server);

        $this->assertStringContainsString('zone "1.168.192.in-addr.arpa" IN {', $output);
        $this->assertStringContainsString('    type secondary;', $output);
        $this->assertStringContainsString('    primaries { 10.0.0.1; };', $output);
        $this->assertStringNotContainsString('view "', $output);
    }

    public function testSecondaryConfOmitsPrimariesWhenNotConfigured(): void
    {
        $server = (new DnsServer())
            ->setName('ns2')
            ->setHostname('10.0.0.2')
            ->setServerType('secondary');
        $view = $this->makeView(1);
        $server->addView($view);

        $domain = (new Domain())->setName('example.com');
        $gen    = $this->makeViewsConfGenerator($server, [$domain], []);
        $output = $gen->generateViewsConf($server);

        $this->assertStringContainsString('zone "example.com" IN {', $output);
        $this->assertStringNotContainsString('primaries', $output);
    }

    // ── Reverse zone aggregation — generateReverseZoneFile ───────────────────

    public function testV4AggregatedChildPtrsAppearInParentZone(): void
    {
        $parent = $this->makeSubnet('192.168.1.0/24');
        $child  = $this->makeSubnet('192.168.1.0/26');

        $output = $this->generator->generateReverseZoneFile($parent, '192.168.1.0/24', null, [$child]);

        $this->assertStringContainsString('$ORIGIN 1.168.192.in-addr.arpa.', $output);
    }

    public function testV4AggregatedChildPtrUsesParentPrefixNotRfc2317(): void
    {
        $child = $this->makeSubnetWithInterface('192.168.1.0/26', '192.168.1.5', 'web01.example.com.');
        $parent = $this->makeSubnet('192.168.1.0/24');
        $parent->setReverseZoneAggregatesV4(true);

        $output = $this->generator->generateReverseZoneFile($parent, '192.168.1.0/24', null, [$child]);

        // PTR label should be last octet only (5), not RFC 2317 sub-/24 format
        $this->assertStringContainsString('5 IN PTR web01.example.com.', $output);
        $this->assertStringNotContainsString('5/26', $output);
    }

    public function testMultipleChildSubnetsPtrsMergedIntoParent(): void
    {
        $child1 = $this->makeSubnetWithInterface('192.168.1.0/26',  '192.168.1.10', 'host10.example.com.');
        $child2 = $this->makeSubnetWithInterface('192.168.1.64/26', '192.168.1.70', 'host70.example.com.');
        $parent = $this->makeSubnet('192.168.1.0/24');

        $output = $this->generator->generateReverseZoneFile($parent, '192.168.1.0/24', null, [$child1, $child2]);

        $this->assertStringContainsString('10 IN PTR host10.example.com.', $output);
        $this->assertStringContainsString('70 IN PTR host70.example.com.', $output);
    }

    public function testNonAggregatingSubnetUnchangedByFeature(): void
    {
        $subnet = $this->makeSubnet('10.0.0.0/24');
        $this->generator->forceSerial(2026010101);

        $withExtra    = $this->generator->generateReverseZoneFile($subnet, '10.0.0.0/24', null, []);
        $withoutExtra = $this->generator->generateReverseZoneFile($subnet, '10.0.0.0/24', null);

        $this->assertSame($withExtra, $withoutExtra);
    }

    // ── Reverse zone aggregation — isAbsorbedFor ─────────────────────────────

    public function testIsAbsorbedForV4ReturnsTrueWhenInsideAggregatingSubnet(): void
    {
        $parent = $this->makeSubnet('192.168.1.0/24');
        $parent->setReverseZoneAggregatesV4(true);

        $child = $this->makeSubnet('192.168.1.0/26');

        $gen = $this->makeGeneratorWithRealCidrContains();

        $this->assertTrue($gen->isAbsorbedFor($child, [$parent, $child], false));
    }

    public function testIsAbsorbedForV4ReturnsFalseWhenNoAggregatingParent(): void
    {
        $parent = $this->makeSubnet('192.168.1.0/24');
        // no aggregation flag set

        $child = $this->makeSubnet('192.168.1.0/26');

        $gen = $this->makeGeneratorWithRealCidrContains();

        $this->assertFalse($gen->isAbsorbedFor($child, [$parent, $child], false));
    }

    public function testIsAbsorbedForV6ReturnsFalseWhenAncestorIsV4Only(): void
    {
        // A v4-only aggregating subnet should not affect v6 CIDRs
        $parent = $this->makeSubnet('192.168.1.0/24');
        $parent->setReverseZoneAggregatesV4(true);

        $child = new Subnet();
        $child->setName('child');
        $child->setIpv6Cidr('2001:db8::/64');

        $gen = $this->makeGeneratorWithRealCidrContains();

        // Checking v6 absorption — parent has no v6 CIDR, so child is never absorbed
        $this->assertFalse($gen->isAbsorbedFor($child, [$parent, $child], true));
    }

    public function testIsAbsorbedForReturnsFalseForSubnetNotContained(): void
    {
        $parent = $this->makeSubnet('10.0.0.0/24');
        $parent->setReverseZoneAggregatesV4(true);

        $other = $this->makeSubnet('192.168.1.0/26');

        $gen = $this->makeGeneratorWithRealCidrContains();

        $this->assertFalse($gen->isAbsorbedFor($other, [$parent, $other], false));
    }

    // ── generateViewsConf absorption ─────────────────────────────────────────

    public function testViewsConfSkipsAbsorbedSubnetZoneDeclaration(): void
    {
        $server = (new DnsServer())->setName('ns1')->setHostname('10.0.0.1');
        $view   = $this->makeView(1);
        $server->addView($view);

        $parent = (new Subnet())->setName('parent')->setIpv4Cidr('192.168.1.0/24');
        $parent->setReverseZoneAggregatesV4(true);
        $child  = (new Subnet())->setName('child')->setIpv4Cidr('192.168.1.0/26');

        $gen    = $this->makeViewsConfGeneratorWithRealCidrContains($server, [], [$parent, $child]);
        $output = $gen->generateViewsConf($server);

        $this->assertStringContainsString('zone "1.168.192.in-addr.arpa" IN {', $output);
        $this->assertStringNotContainsString('0/26.1.168.192.in-addr.arpa', $output);
    }

    // ── generateViewsConf helpers ─────────────────────────────────────────────

    /**
     * Create a subnet with one interface that has an IPv4 address and a canonical A record.
     */
    private function makeSubnetWithInterface(string $cidr, string $ip, string $fqdn): Subnet
    {
        $subnet = $this->makeSubnet($cidr);

        $ipAddr = new IpAddress();
        (new \ReflectionProperty(IpAddress::class, 'address'))->setValue($ipAddr, $ip);

        $domain = (new Domain())->setName('example.com');

        $record = (new DomainRecord())
            ->setHostname(explode('.example.com.', $fqdn)[0])
            ->setType(RecordType::A)
            ->setIsCanonical(true)
            ->setDomain($domain);

        $iface = new NetworkInterface();
        (new \ReflectionProperty(NetworkInterface::class, 'ipAddress'))->setValue($iface, $ipAddr);
        (new \ReflectionProperty(NetworkInterface::class, 'domainRecords'))->setValue($iface, new ArrayCollection([$record]));
        (new \ReflectionProperty(NetworkInterface::class, 'deletedAt'))->setValue($iface, null);

        (new \ReflectionProperty(Subnet::class, 'interfaces'))->setValue($subnet, new ArrayCollection([$iface]));

        return $subnet;
    }

    /**
     * Create a DnsConfigGenerator whose SubnetRepository stub delegates cidrContains
     * to the real IPLib-based logic.
     */
    private function makeGeneratorWithRealCidrContains(): DnsConfigGenerator
    {
        $subnetRepo = $this->createStub(SubnetRepository::class);
        $subnetRepo->method('cidrContains')->willReturnCallback(function (string $parent, string $child): bool {
            if ($parent === $child) {
                return false;
            }
            $pRange = IpLibFactory::parseRangeString($parent);
            $cRange = IpLibFactory::parseRangeString($child);
            if (!$pRange || !$cRange) {
                return false;
            }
            return $pRange->contains($cRange->getStartAddress()) && $pRange->contains($cRange->getEndAddress());
        });

        return new DnsConfigGenerator(
            $this->createStub(DomainRepository::class),
            $subnetRepo,
            $this->createStub(DnssecPolicyRepository::class),
            $this->createStub(DnsAclRepository::class),
        );
    }

    private function makeViewsConfGeneratorWithRealCidrContains(DnsServer $server, array $domains, array $subnets): DnsConfigGenerator
    {
        $domainQuery = $this->createStub(Query::class);
        $domainQuery->method('getResult')->willReturn($domains);
        $domainQb = $this->createStub(QueryBuilder::class);
        $domainQb->method('join')->willReturnSelf();
        $domainQb->method('where')->willReturnSelf();
        $domainQb->method('setParameter')->willReturnSelf();
        $domainQb->method('orderBy')->willReturnSelf();
        $domainQb->method('getQuery')->willReturn($domainQuery);

        $subnetQuery = $this->createStub(Query::class);
        $subnetQuery->method('getResult')->willReturn($subnets);
        $subnetQb = $this->createStub(QueryBuilder::class);
        $subnetQb->method('join')->willReturnSelf();
        $subnetQb->method('where')->willReturnSelf();
        $subnetQb->method('setParameter')->willReturnSelf();
        $subnetQb->method('orderBy')->willReturnSelf();
        $subnetQb->method('getQuery')->willReturn($subnetQuery);

        $domainRepo = $this->createStub(DomainRepository::class);
        $domainRepo->method('createQueryBuilder')->willReturn($domainQb);

        $subnetRepo = $this->createStub(SubnetRepository::class);
        $subnetRepo->method('createQueryBuilder')->willReturn($subnetQb);
        $subnetRepo->method('cidrContains')->willReturnCallback(function (string $parent, string $child): bool {
            if ($parent === $child) {
                return false;
            }
            $pRange = IpLibFactory::parseRangeString($parent);
            $cRange = IpLibFactory::parseRangeString($child);
            if (!$pRange || !$cRange) {
                return false;
            }
            return $pRange->contains($cRange->getStartAddress()) && $pRange->contains($cRange->getEndAddress());
        });

        $policyRepo = $this->createStub(DnssecPolicyRepository::class);
        $policyRepo->method('findBy')->willReturn([]);

        $aclRepo = $this->createStub(DnsAclRepository::class);
        $aclRepo->method('findBy')->willReturn([]);

        return new DnsConfigGenerator($domainRepo, $subnetRepo, $policyRepo, $aclRepo);
    }

    private function makeServerWithDdns(string $name, TsigAlgorithm $algo, string $secret): DnsServer
    {
        return (new DnsServer())
            ->setName($name)
            ->setHostname('10.0.0.1')
            ->setDdnsAlgorithm($algo)
            ->setDdnsSecret($secret);
    }

    private function makeViewsConfGenerator(DnsServer $server, array $domains, array $subnets): DnsConfigGenerator
    {
        $domainQuery = $this->createStub(Query::class);
        $domainQuery->method('getResult')->willReturn($domains);
        $domainQb = $this->createStub(QueryBuilder::class);
        $domainQb->method('join')->willReturnSelf();
        $domainQb->method('where')->willReturnSelf();
        $domainQb->method('setParameter')->willReturnSelf();
        $domainQb->method('orderBy')->willReturnSelf();
        $domainQb->method('getQuery')->willReturn($domainQuery);

        $subnetQuery = $this->createStub(Query::class);
        $subnetQuery->method('getResult')->willReturn($subnets);
        $subnetQb = $this->createStub(QueryBuilder::class);
        $subnetQb->method('join')->willReturnSelf();
        $subnetQb->method('where')->willReturnSelf();
        $subnetQb->method('setParameter')->willReturnSelf();
        $subnetQb->method('orderBy')->willReturnSelf();
        $subnetQb->method('getQuery')->willReturn($subnetQuery);

        $domainRepo = $this->createStub(DomainRepository::class);
        $domainRepo->method('createQueryBuilder')->willReturn($domainQb);

        $subnetRepo = $this->createStub(SubnetRepository::class);
        $subnetRepo->method('createQueryBuilder')->willReturn($subnetQb);

        $policyRepo = $this->createStub(DnssecPolicyRepository::class);
        $policyRepo->method('findBy')->willReturn([]);

        $aclRepo = $this->createStub(DnsAclRepository::class);
        $aclRepo->method('findBy')->willReturn([]);

        return new DnsConfigGenerator($domainRepo, $subnetRepo, $policyRepo, $aclRepo);
    }
}
