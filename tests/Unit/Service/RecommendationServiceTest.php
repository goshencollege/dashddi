<?php

namespace App\Tests\Unit\Service;

use App\Entity\DnsView;
use App\Entity\Domain;
use App\Entity\DomainRecord;
use App\Entity\NetworkInterface;
use App\Entity\VirtualIp;
use App\Enum\RecordType;
use App\Service\DnsViewResolver;
use App\Service\RecommendationService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

class RecommendationServiceTest extends TestCase
{
    private RecommendationService $service;

    protected function setUp(): void
    {
        $this->service = new RecommendationService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(DnsViewResolver::class),
        );
    }

    public function testDhcpExclusionTagConstantValue(): void
    {
        $this->assertSame('dhcp-ok', RecommendationService::DHCP_EXCLUSION_TAG);
    }

    public function testFindSubnetForLastDhcpIpReturnsNullWhenIpIsNull(): void
    {
        $iface = new NetworkInterface();
        // lastDhcpIp is null by default — no DB call should be made

        $result = $this->service->findSubnetForLastDhcpIp($iface);

        $this->assertNull($result);
    }

    public function testFindInterfaceForDnsRecordReturnsNullWhenAlreadyLinkedToInterface(): void
    {
        $record = new DomainRecord();
        $record->setNetworkInterface(new NetworkInterface());
        // Already linked — method must return null without touching the DB

        $result = $this->service->findInterfaceForDnsRecord($record);

        $this->assertNull($result);
    }

    public function testFindInterfaceForDnsRecordReturnsNullWhenAlreadyLinkedToVip(): void
    {
        $record = new DomainRecord();
        $record->setVirtualIp(new VirtualIp());

        $result = $this->service->findInterfaceForDnsRecord($record);

        $this->assertNull($result);
    }

    public function testFindVipForDnsRecordReturnsNullWhenAlreadyLinkedToInterface(): void
    {
        $record = new DomainRecord();
        $record->setNetworkInterface(new NetworkInterface());

        $result = $this->service->findVipForDnsRecord($record);

        $this->assertNull($result);
    }

    public function testFindVipForDnsRecordReturnsNullWhenAlreadyLinkedToVip(): void
    {
        $record = new DomainRecord();
        $record->setVirtualIp(new VirtualIp());

        $result = $this->service->findVipForDnsRecord($record);

        $this->assertNull($result);
    }

    public function testFindInterfaceForDnsRecordReturnsNullForNonAorAAAAType(): void
    {
        $record = new DomainRecord();
        $record->setType(RecordType::CNAME);
        $record->setValue('alias');
        // No linked entity, but type is not A/AAAA — DB lookup should not be attempted

        $result = $this->service->findInterfaceForDnsRecord($record);

        $this->assertNull($result);
    }

    // ── findReparentableDnsRecords / countExcludedReparentRecords ───────────────

    private function buildDomain(int $id, string $name): Domain
    {
        $domain = new Domain();
        $domain->setName($name);
        $prop = new \ReflectionProperty(Domain::class, 'id');
        $prop->setValue($domain, $id);
        return $domain;
    }

    private function buildRecord(int $id, Domain $domain, string $hostname, RecordType $type, string $value = 'x'): DomainRecord
    {
        $record = new DomainRecord();
        $record->setDomain($domain);
        $record->setHostname($hostname);
        $record->setType($type);
        $record->setValue($value);
        $prop = new \ReflectionProperty(DomainRecord::class, 'id');
        $prop->setValue($record, $id);
        $domain->getRecords()->add($record);
        return $record;
    }

    private function serviceWithDomains(array $domains, ?DnsViewResolver $viewResolver = null): RecommendationService
    {
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findAll')->willReturn($domains);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        return new RecommendationService($em, $viewResolver ?? $this->createStub(DnsViewResolver::class));
    }

    public function testFindReparentableDnsRecordsMatchesSingleLabelChain(): void
    {
        $parent = $this->buildDomain(1, 'goshen.edu');
        $child  = $this->buildDomain(2, 'switches.goshen.edu');
        $record = $this->buildRecord(10, $parent, '1-1-1.cx-core-un.switches', RecordType::A, '10.0.0.1');

        $service = $this->serviceWithDomains([$parent, $child]);
        $rows    = $service->findReparentableDnsRecords();

        $this->assertCount(1, $rows);
        $this->assertSame($record->getId(), $rows[0]['record_id']);
        $this->assertSame('switches.goshen.edu', $rows[0]['target_domain_name']);
        $this->assertSame('1-1-1.cx-core-un', $rows[0]['new_hostname']);
    }

    public function testFindReparentableDnsRecordsHostnameExactlyEqualToChainBecomesApex(): void
    {
        $parent = $this->buildDomain(1, 'goshen.edu');
        $child  = $this->buildDomain(2, 'switches.goshen.edu');
        $this->buildRecord(10, $parent, 'switches', RecordType::A, '10.0.0.1');

        $service = $this->serviceWithDomains([$parent, $child]);
        $rows    = $service->findReparentableDnsRecords();

        $this->assertCount(1, $rows);
        $this->assertSame('@', $rows[0]['new_hostname']);
    }

    public function testFindReparentableDnsRecordsMatchesWildcardHostname(): void
    {
        $parent = $this->buildDomain(1, 'goshen.edu');
        $child  = $this->buildDomain(2, 'switches.goshen.edu');
        $this->buildRecord(10, $parent, '*.switches', RecordType::A, '10.0.0.1');

        $service = $this->serviceWithDomains([$parent, $child]);
        $rows    = $service->findReparentableDnsRecords();

        $this->assertCount(1, $rows);
        $this->assertSame('*', $rows[0]['new_hostname']);
    }

    public function testFindReparentableDnsRecordsPicksDeepestMatchingDomain(): void
    {
        $parent      = $this->buildDomain(1, 'goshen.edu');
        $middleChild = $this->buildDomain(2, 'switches.goshen.edu');
        $deepChild   = $this->buildDomain(3, 'core.switches.goshen.edu');
        $record      = $this->buildRecord(10, $parent, 'x.core.switches', RecordType::A, '10.0.0.1');

        $service = $this->serviceWithDomains([$parent, $middleChild, $deepChild]);
        $rows    = $service->findReparentableDnsRecords();

        $this->assertCount(1, $rows);
        $this->assertSame($deepChild->getId(), $rows[0]['target_domain_id']);
        $this->assertSame('x', $rows[0]['new_hostname']);
    }

    public function testFindReparentableDnsRecordsExcludesDelegationNsRecord(): void
    {
        $parent = $this->buildDomain(1, 'goshen.edu');
        $child  = $this->buildDomain(2, 'switches.goshen.edu');
        $this->buildRecord(10, $parent, 'switches', RecordType::NS, 'ns1.switches.goshen.edu.');

        $service = $this->serviceWithDomains([$parent, $child]);

        $this->assertCount(0, $service->findReparentableDnsRecords());
        $this->assertSame(1, $service->countExcludedReparentRecords());
    }

    public function testFindReparentableDnsRecordsExcludesGlueRecordForInBailiwickNameserver(): void
    {
        $parent = $this->buildDomain(1, 'goshen.edu');
        $child  = $this->buildDomain(2, 'switches.goshen.edu');
        // Delegation NS in the parent, targeting a nameserver inside the child's namespace.
        $this->buildRecord(10, $parent, 'switches', RecordType::NS, 'ns1.switches.goshen.edu.');
        // Its glue A record, also in the parent — must not move even though it matches by name.
        $glue = $this->buildRecord(11, $parent, 'ns1.switches', RecordType::A, '10.0.0.1');

        $service = $this->serviceWithDomains([$parent, $child]);
        $rows    = $service->findReparentableDnsRecords();

        $this->assertCount(0, $rows);
        // Both the delegation NS and the glue A record are excluded.
        $this->assertSame(2, $service->countExcludedReparentRecords());
    }

    public function testFindReparentableDnsRecordsMovesUnrelatedRecordsAlongsideExcludedGlue(): void
    {
        $parent = $this->buildDomain(1, 'goshen.edu');
        $child  = $this->buildDomain(2, 'switches.goshen.edu');
        $this->buildRecord(10, $parent, 'switches', RecordType::NS, 'ns1.switches.goshen.edu.');
        $this->buildRecord(11, $parent, 'ns1.switches', RecordType::A, '10.0.0.1');
        $ordinary = $this->buildRecord(12, $parent, '1-1-1.cx-core-un.switches', RecordType::A, '10.0.0.2');

        $service = $this->serviceWithDomains([$parent, $child]);
        $rows    = $service->findReparentableDnsRecords();

        $this->assertCount(1, $rows);
        $this->assertSame($ordinary->getId(), $rows[0]['record_id']);
        $this->assertSame(2, $service->countExcludedReparentRecords());
    }

    public function testNarrowViewsForDomainRemovesViewsNotAvailableOnTargetDomain(): void
    {
        $target = $this->buildDomain(2, 'switches.goshen.edu');
        $iface  = new NetworkInterface();

        $keep = new DnsView();
        $keep->setName('internal');
        $prop = new \ReflectionProperty(DnsView::class, 'id');
        $prop->setValue($keep, 100);

        $drop = new DnsView();
        $drop->setName('public');
        $prop->setValue($drop, 200);

        $record = new DomainRecord();
        $record->setType(RecordType::A);
        $record->setNetworkInterface($iface);
        $record->addView($keep);
        $record->addView($drop);

        $viewResolver = $this->createStub(DnsViewResolver::class);
        $viewResolver->method('availableViewsFor')->willReturn([$keep]);

        $service = $this->serviceWithDomains([], $viewResolver);
        $service->narrowViewsForDomain($record, $target);

        $this->assertTrue($record->getViews()->contains($keep));
        $this->assertFalse($record->getViews()->contains($drop));
    }

    public function testNarrowViewsForDomainIsNoOpForRecordsWithoutInterface(): void
    {
        $target = $this->buildDomain(2, 'switches.goshen.edu');
        $record = new DomainRecord();
        $record->setType(RecordType::A);

        $viewResolver = $this->createStub(DnsViewResolver::class);
        $viewResolver->method('availableViewsFor')->willReturn([]);

        $service = $this->serviceWithDomains([], $viewResolver);
        // Should not throw despite no interface linked, and should leave views untouched.
        $service->narrowViewsForDomain($record, $target);

        $this->assertCount(0, $record->getViews());
    }
}
