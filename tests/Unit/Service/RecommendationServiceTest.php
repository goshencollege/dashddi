<?php

namespace App\Tests\Unit\Service;

use App\Entity\DomainRecord;
use App\Entity\NetworkInterface;
use App\Entity\VirtualIp;
use App\Enum\RecordType;
use App\Service\DnsViewResolver;
use App\Service\RecommendationService;
use Doctrine\ORM\EntityManagerInterface;
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
}
