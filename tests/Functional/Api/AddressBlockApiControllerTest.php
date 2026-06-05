<?php

namespace App\Tests\Functional\Api;

use App\Entity\AddressBlock;
use App\Entity\Subnet;
use App\Enum\BlockType;
use App\Tests\Functional\AppWebTestCase;

class AddressBlockApiControllerTest extends AppWebTestCase
{
    private function makeSubnet(string $name, string $cidr = '10.99.0.0/24'): Subnet
    {
        $subnet = (new Subnet())->setName($name)->setIpv4Cidr($cidr);
        $this->em->persist($subnet);
        $this->em->flush();
        return $subnet;
    }

    private function makeBlock(Subnet $subnet, string $start, string $end, BlockType $type = BlockType::Reserved): AddressBlock
    {
        $block = (new AddressBlock())
            ->setSubnet($subnet)
            ->setType($type)
            ->setStartIp($start)
            ->setEndIp($end);
        $this->em->persist($block);
        $this->em->flush();
        return $block;
    }

    public function testCreateBlockBlockedByExactOverlap(): void
    {
        $subnet = $this->makeSubnet('Test Subnet');
        $this->makeBlock($subnet, '10.99.0.10', '10.99.0.50');

        $this->apiRequest('POST', '/api/address-blocks', [
            'subnet_id' => $subnet->getId(),
            'type'      => 'reserved',
            'start_ip'  => '10.99.0.10',
            'end_ip'    => '10.99.0.50',
        ]);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateBlockBlockedByPartialOverlap(): void
    {
        $subnet = $this->makeSubnet('Test Subnet');
        $this->makeBlock($subnet, '10.99.0.10', '10.99.0.50');

        // New block starts before and ends inside the existing block
        $this->apiRequest('POST', '/api/address-blocks', [
            'subnet_id' => $subnet->getId(),
            'type'      => 'fixed',
            'start_ip'  => '10.99.0.40',
            'end_ip'    => '10.99.0.60',
        ]);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateBlockBlockedWhenContainedWithinExisting(): void
    {
        $subnet = $this->makeSubnet('Test Subnet');
        $this->makeBlock($subnet, '10.99.0.10', '10.99.0.50');

        // New block is fully contained within the existing block
        $this->apiRequest('POST', '/api/address-blocks', [
            'subnet_id' => $subnet->getId(),
            'type'      => 'dynamic',
            'start_ip'  => '10.99.0.20',
            'end_ip'    => '10.99.0.30',
        ]);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testUpdateBlockBlockedByOverlap(): void
    {
        $subnet = $this->makeSubnet('Test Subnet');
        $this->makeBlock($subnet, '10.99.0.10', '10.99.0.50');
        $other = $this->makeBlock($subnet, '10.99.0.60', '10.99.0.80', BlockType::Fixed);

        $this->apiRequest('PATCH', "/api/address-blocks/{$other->getId()}", [
            'start_ip' => '10.99.0.40',
            'end_ip'   => '10.99.0.70',
        ]);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testUpdateBlockToSameRangeIsAllowed(): void
    {
        $subnet = $this->makeSubnet('Test Subnet');
        $block = $this->makeBlock($subnet, '10.99.0.10', '10.99.0.50');

        $this->apiRequest('PATCH', "/api/address-blocks/{$block->getId()}", [
            'start_ip' => '10.99.0.10',
            'end_ip'   => '10.99.0.50',
        ]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateNonOverlappingAdjacentBlocksAllowed(): void
    {
        $subnet = $this->makeSubnet('Test Subnet');
        $this->makeBlock($subnet, '10.99.0.10', '10.99.0.50');

        // Starts exactly after the existing block ends — no overlap
        $this->apiRequest('POST', '/api/address-blocks', [
            'subnet_id' => $subnet->getId(),
            'type'      => 'fixed',
            'start_ip'  => '10.99.0.51',
            'end_ip'    => '10.99.0.80',
        ]);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateBlockInDifferentSubnetNotBlocked(): void
    {
        $subnetA = $this->makeSubnet('Subnet A', '10.99.0.0/24');
        $subnetB = $this->makeSubnet('Subnet B', '10.100.0.0/24');
        $this->makeBlock($subnetA, '10.99.0.10', '10.99.0.50');

        // Same IP range but different subnet — should be allowed
        $this->apiRequest('POST', '/api/address-blocks', [
            'subnet_id' => $subnetB->getId(),
            'type'      => 'reserved',
            'start_ip'  => '10.99.0.10',
            'end_ip'    => '10.99.0.50',
        ]);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
    }
}
