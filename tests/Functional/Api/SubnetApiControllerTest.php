<?php

namespace App\Tests\Functional\Api;

use App\Entity\Subnet;
use App\Tests\Functional\AppWebTestCase;

class SubnetApiControllerTest extends AppWebTestCase
{
    private function makeSubnet(string $name, string $ipv4Cidr = '10.99.0.0/24', bool $isContainer = false, ?string $ipv6Cidr = null): Subnet
    {
        $subnet = (new Subnet())->setName($name)->setIpv4Cidr($ipv4Cidr)->setIsContainer($isContainer);
        if ($ipv6Cidr !== null) {
            $subnet->setIpv6Cidr($ipv6Cidr);
        }
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

    // -------------------------------------------------------------------------
    // Terminal subnet overlap validation
    // -------------------------------------------------------------------------

    public function testCreateTerminalSubnetBlockedByIpv4Overlap(): void
    {
        $this->makeSubnet('Existing', '10.1.0.0/24');

        $this->apiRequest('POST', '/api/subnets', ['name' => 'Conflicting', 'ipv4_cidr' => '10.1.0.0/24']);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateTerminalSubnetBlockedWhenContainedWithinExisting(): void
    {
        $this->makeSubnet('Existing', '10.2.0.0/22');

        // 10.2.1.0/24 is fully contained within 10.2.0.0/22 — should be blocked
        $this->apiRequest('POST', '/api/subnets', ['name' => 'Contained', 'ipv4_cidr' => '10.2.1.0/24']);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateTerminalSubnetBlockedByIpv6Overlap(): void
    {
        $subnet = (new Subnet())->setName('Existing V6')->setIpv6Cidr('2001:db8::/48');
        $this->em->persist($subnet);
        $this->em->flush();

        $this->apiRequest('POST', '/api/subnets', ['name' => 'Conflicting V6', 'ipv6_cidr' => '2001:db8::/48']);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testUpdateTerminalSubnetBlockedByIpv4Overlap(): void
    {
        $this->makeSubnet('Subnet A', '10.3.0.0/24');
        $b = $this->makeSubnet('Subnet B', '10.4.0.0/24');

        $this->apiRequest('PATCH', "/api/subnets/{$b->getId()}", ['ipv4_cidr' => '10.3.0.0/24']);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testUpdateTerminalSubnetToItsOwnCidrIsAllowed(): void
    {
        $subnet = $this->makeSubnet('Self', '10.5.0.0/24');

        $this->apiRequest('PATCH', "/api/subnets/{$subnet->getId()}", ['ipv4_cidr' => '10.5.0.0/24']);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testContainerSubnetAllowedToOverlapTerminalSubnet(): void
    {
        $this->makeSubnet('Terminal', '10.6.0.0/24');

        $this->apiRequest('POST', '/api/subnets', [
            'name'         => 'Container',
            'ipv4_cidr'    => '10.6.0.0/24',
            'is_container' => true,
        ]);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
    }

    public function testTerminalSubnetAllowedWhenOnlyContainerOverlaps(): void
    {
        $this->makeSubnet('Container', '10.7.0.0/24', isContainer: true);

        $this->apiRequest('POST', '/api/subnets', ['name' => 'Terminal', 'ipv4_cidr' => '10.7.0.0/24']);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
    }
}
