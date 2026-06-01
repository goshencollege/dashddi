<?php

namespace App\Tests\Unit\Service;

use App\Entity\DnsView;
use App\Entity\Subnet;
use App\Entity\SubnetRecord;
use App\Enum\RecordType;
use App\Repository\DnsAclRepository;
use App\Repository\DnssecPolicyRepository;
use App\Repository\DomainRepository;
use App\Repository\SubnetRepository;
use App\Service\DnsConfigGenerator;
use App\Service\FcrdnsChecker;
use Doctrine\Common\Collections\ArrayCollection;
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
            $this->createStub(FcrdnsChecker::class),
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
}
