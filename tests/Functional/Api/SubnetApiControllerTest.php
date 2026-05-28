<?php

namespace App\Tests\Functional\Api;

use App\Entity\Subnet;
use App\Tests\Functional\AppWebTestCase;

class SubnetApiControllerTest extends AppWebTestCase
{
    private function makeSubnet(string $name): Subnet
    {
        $subnet = (new Subnet())->setName($name)->setIpv4Cidr('10.99.0.0/24');
        $this->em->persist($subnet);
        $this->em->flush();
        return $subnet;
    }

    public function testIndexReturnsJson(): void
    {
        $this->makeSubnet('API Subnet');
        $data = $this->apiRequest('GET', '/api/subnets');
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
    }

    public function testIndexFiltersByName(): void
    {
        $this->makeSubnet('Unique ZZZ Subnet');
        $data = $this->apiRequest('GET', '/api/subnets?name=Unique+ZZZ');
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('Unique ZZZ Subnet', $data[0]['name']);
    }

    public function testShow(): void
    {
        $subnet = $this->makeSubnet('Show Subnet');
        $data = $this->apiRequest('GET', "/api/subnets/{$subnet->getId()}");
        $this->assertSame($subnet->getId(), $data['id']);
        $this->assertSame('Show Subnet', $data['name']);
        $this->assertSame('10.99.0.0/24', $data['ipv4_cidr']);
    }

    public function testCreate(): void
    {
        $data = $this->apiRequest('POST', '/api/subnets', [
            'name'      => 'Created Subnet',
            'ipv4_cidr' => '10.100.0.0/24',
        ]);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame('Created Subnet', $data['name']);
        $this->assertSame('10.100.0.0/24', $data['ipv4_cidr']);
    }

    public function testCreateRequiresName(): void
    {
        $this->apiRequest('POST', '/api/subnets', ['ipv4_cidr' => '10.101.0.0/24']);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testUpdate(): void
    {
        $subnet = $this->makeSubnet('Patch Subnet');
        $data = $this->apiRequest('PATCH', "/api/subnets/{$subnet->getId()}", ['name' => 'Patched Subnet']);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('Patched Subnet', $data['name']);
    }

    public function testDelete(): void
    {
        $subnet = $this->makeSubnet('Delete Subnet');
        $this->apiRequest('DELETE', "/api/subnets/{$subnet->getId()}");
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }
}
