<?php

namespace App\Tests\Unit\Service;

use App\Entity\Subnet;
use App\Repository\SubnetRepository;
use App\Service\SubnetAllocationService;
use PHPUnit\Framework\TestCase;

class SubnetAllocationServiceTest extends TestCase
{
    private SubnetRepository $subnetRepo;
    private SubnetAllocationService $service;

    protected function setUp(): void
    {
        $this->subnetRepo = $this->createStub(SubnetRepository::class);
        $this->service = new SubnetAllocationService($this->subnetRepo);
    }

    private function makeSubnet(?string $ipv4Cidr, ?string $ipv6Cidr = null): Subnet
    {
        $subnet = new Subnet();
        $subnet->setIpv4Cidr($ipv4Cidr);
        $subnet->setIpv6Cidr($ipv6Cidr);
        return $subnet;
    }

    public function testFindNextAvailableIpv4CidrReturnsErrorWithoutContainerCidr(): void
    {
        $container = $this->makeSubnet(null);

        $result = $this->service->findNextAvailableIpv4Cidr($container, 24);

        $this->assertNull($result['cidr']);
        $this->assertNotNull($result['error']);
    }

    public function testFindNextAvailableIpv4CidrReturnsFirstBlockWhenEmpty(): void
    {
        $container = $this->makeSubnet('10.0.0.0/16');
        $this->subnetRepo->method('findV4ContainedBy')->willReturn([]);

        $result = $this->service->findNextAvailableIpv4Cidr($container, 24);

        $this->assertSame('10.0.0.0/24', $result['cidr']);
        $this->assertNull($result['error']);
    }

    public function testFindNextAvailableIpv4CidrSkipsOccupiedBlocks(): void
    {
        $container = $this->makeSubnet('10.0.0.0/16');
        $existing = $this->makeSubnet('10.0.0.0/24');
        $existing2 = $this->makeSubnet('10.0.1.0/24');
        $this->subnetRepo->method('findV4ContainedBy')->willReturn([$existing, $existing2]);

        $result = $this->service->findNextAvailableIpv4Cidr($container, 24);

        $this->assertSame('10.0.2.0/24', $result['cidr']);
    }

    public function testFindNextAvailableIpv4CidrSkipsBlockOccupiedByLargerExistingSubnet(): void
    {
        $container = $this->makeSubnet('10.0.0.0/16');
        // A /23 already covers both 10.0.0.0/24 and 10.0.1.0/24
        $existing = $this->makeSubnet('10.0.0.0/23');
        $this->subnetRepo->method('findV4ContainedBy')->willReturn([$existing]);

        $result = $this->service->findNextAvailableIpv4Cidr($container, 24);

        $this->assertSame('10.0.2.0/24', $result['cidr']);
    }

    public function testFindNextAvailableIpv4CidrReturnsErrorWhenPrefixNotLargerThanContainer(): void
    {
        $container = $this->makeSubnet('10.0.0.0/24');
        $this->subnetRepo->method('findV4ContainedBy')->willReturn([]);

        $result = $this->service->findNextAvailableIpv4Cidr($container, 24);

        $this->assertNull($result['cidr']);
        $this->assertNotNull($result['error']);
    }

    public function testFindNextAvailableIpv4CidrReturnsErrorWhenContainerFull(): void
    {
        $container = $this->makeSubnet('10.0.0.0/23');
        $existing = [$this->makeSubnet('10.0.0.0/24'), $this->makeSubnet('10.0.1.0/24')];
        $this->subnetRepo->method('findV4ContainedBy')->willReturn($existing);

        $result = $this->service->findNextAvailableIpv4Cidr($container, 24);

        $this->assertNull($result['cidr']);
        $this->assertStringContainsString('No available', $result['error']);
    }

    public function testDeriveCorrespondingIpv6CidrEncodesSlashTwentyFourOctetAsLowByte(): void
    {
        $container = $this->makeSubnet('10.0.0.0/16', '2001:db8::/32');
        $this->subnetRepo->method('findV6ContainedBy')->willReturn([]);

        $result = $this->service->deriveCorrespondingIpv6Cidr($container, '10.0.5.0/24', 64);

        // 5 in hex is 05 — matches the manual "hex-encode the 3rd octet into a v6 group" convention.
        $this->assertSame('2001:db8:0:5::/64', $result['cidr']);
        $this->assertNull($result['error']);
    }

    public function testDeriveCorrespondingIpv6CidrHandlesNonOctetAlignedPrefix(): void
    {
        $container = $this->makeSubnet('10.0.0.0/16', '2001:db8::/32');
        $this->subnetRepo->method('findV6ContainedBy')->willReturn([]);

        // /25 subnets need 9 index bits (25-16); a /55 v6 slot inside /32 leaves 23 bits, plenty of room.
        $result = $this->service->deriveCorrespondingIpv6Cidr($container, '10.0.5.128/25', 55);

        $this->assertNotNull($result['cidr']);
        $this->assertNull($result['error']);
    }

    public function testDeriveCorrespondingIpv6CidrErrorsWhenV6SlotTooSmall(): void
    {
        $container = $this->makeSubnet('10.0.0.0/8', '2001:db8::/32');
        $this->subnetRepo->method('findV6ContainedBy')->willReturn([]);

        // /24 in a /8 needs 16 index bits, but a /40 slot in a /32 container only has 8 bits available.
        $result = $this->service->deriveCorrespondingIpv6Cidr($container, '10.5.0.0/24', 40);

        $this->assertNull($result['cidr']);
        $this->assertStringContainsString('too small', $result['error']);
    }

    public function testDeriveCorrespondingIpv6CidrErrorsWhenNoV6Cidr(): void
    {
        $container = $this->makeSubnet('10.0.0.0/16', null);

        $result = $this->service->deriveCorrespondingIpv6Cidr($container, '10.0.5.0/24', 64);

        $this->assertNull($result['cidr']);
        $this->assertNotNull($result['error']);
    }

    public function testDeriveCorrespondingIpv6CidrErrorsWhenV4CidrNotContained(): void
    {
        $container = $this->makeSubnet('10.0.0.0/16', '2001:db8::/32');

        $result = $this->service->deriveCorrespondingIpv6Cidr($container, '192.168.5.0/24', 64);

        $this->assertNull($result['cidr']);
        $this->assertNotNull($result['error']);
    }

    public function testDeriveCorrespondingIpv6CidrErrorsWhenComputedSlotOccupied(): void
    {
        $container = $this->makeSubnet('10.0.0.0/16', '2001:db8::/32');
        $occupied = $this->makeSubnet(null, '2001:db8:0:5::/64');
        $this->subnetRepo->method('findV6ContainedBy')->willReturn([$occupied]);

        $result = $this->service->deriveCorrespondingIpv6Cidr($container, '10.0.5.0/24', 64);

        $this->assertNull($result['cidr']);
        $this->assertStringContainsString('overlaps', $result['error']);
    }
}
