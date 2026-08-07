<?php

namespace App\Tests\Functional\Api;

use App\Entity\Host;
use App\Entity\NetworkInterface;
use App\Entity\Subnet;
use App\Entity\VirtualIp;
use App\Enum\VirtualIpProtocol;
use App\Tests\Functional\AppWebTestCase;

class VirtualIpApiControllerTest extends AppWebTestCase
{
    private function makeSubnet(string $ipv4Cidr = '10.78.0.0/24'): Subnet
    {
        $subnet = (new Subnet())->setName('VIP API Subnet')->setIpv4Cidr($ipv4Cidr);
        $this->em->persist($subnet);
        $this->em->flush();
        return $subnet;
    }

    private function makeVip(Subnet $subnet, string $label = 'test-vip'): VirtualIp
    {
        $vip = (new VirtualIp())->setLabel($label)->setProtocol(VirtualIpProtocol::Vrrp)->setSubnet($subnet);
        $this->em->persist($vip);
        $this->em->flush();
        return $vip;
    }

    private function makeInterface(Subnet $subnet): NetworkInterface
    {
        $host = (new Host())->setName('vip-test-host');
        $this->em->persist($host);
        $iface = (new NetworkInterface())->setHost($host)->setMacAddress('aa:bb:cc:dd:ee:01')->setSubnet($subnet);
        $this->em->persist($iface);
        $this->em->flush();
        return $iface;
    }

    // -------------------------------------------------------------------------
    // List
    // -------------------------------------------------------------------------

    public function testIndexReturnsJson(): void
    {
        $subnet = $this->makeSubnet();
        $this->makeVip($subnet);
        $data = $this->apiRequest('GET', '/api/virtual-ips');
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
    }

    public function testIndexFiltersBySubnetId(): void
    {
        $subnet = $this->makeSubnet();
        $this->makeVip($subnet, 'target-vip');
        $data = $this->apiRequest('GET', "/api/virtual-ips?subnet_id={$subnet->getId()}");
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('target-vip', $data[0]['label']);
    }

    public function testDeletedVipsNotInDefaultIndex(): void
    {
        $vip = $this->makeVip($this->makeSubnet(), 'hidden-vip');
        $vip->softDelete();
        $this->em->flush();

        $data = $this->apiRequest('GET', "/api/virtual-ips?subnet_id={$vip->getSubnet()->getId()}");
        $this->assertIsArray($data);
        $this->assertCount(0, $data);
    }

    public function testIndexWithDeletedAllIncludesDeleted(): void
    {
        $vip = $this->makeVip($this->makeSubnet(), 'deleted-vip');
        $vip->softDelete();
        $this->em->flush();

        $data = $this->apiRequest('GET', "/api/virtual-ips?subnet_id={$vip->getSubnet()->getId()}&deleted=all");
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
    }

    // -------------------------------------------------------------------------
    // Show
    // -------------------------------------------------------------------------

    public function testShow(): void
    {
        $subnet = $this->makeSubnet();
        $vip = $this->makeVip($subnet, 'show-vip');
        $data = $this->apiRequest('GET', "/api/virtual-ips/{$vip->getId()}");
        $this->assertSame($vip->getId(), $data['id']);
        $this->assertSame('show-vip', $data['label']);
        $this->assertSame('vrrp', $data['protocol']);
        $this->assertSame($subnet->getId(), $data['subnet_id']);
        $this->assertArrayHasKey('member_interface_ids', $data);
    }

    public function testShowDeletedReturns404(): void
    {
        $vip = $this->makeVip($this->makeSubnet());
        $vip->softDelete();
        $this->em->flush();

        $this->apiRequest('GET', "/api/virtual-ips/{$vip->getId()}");
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    public function testCreate(): void
    {
        $subnet = $this->makeSubnet();
        $data = $this->apiRequest('POST', '/api/virtual-ips', [
            'label'     => 'new-vip',
            'subnet_id' => $subnet->getId(),
            'protocol'  => 'hsrp',
            'vrid'      => 5,
        ]);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame('new-vip', $data['label']);
        $this->assertSame('hsrp', $data['protocol']);
        $this->assertSame(5, $data['vrid']);
        $this->assertSame($subnet->getId(), $data['subnet_id']);
        $this->assertNull($data['ip_address']);
    }

    public function testCreateRequiresLabel(): void
    {
        $subnet = $this->makeSubnet();
        $this->apiRequest('POST', '/api/virtual-ips', ['subnet_id' => $subnet->getId()]);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateRequiresSubnetId(): void
    {
        $this->apiRequest('POST', '/api/virtual-ips', ['label' => 'orphan-vip']);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateWithSpecifiedIpv4(): void
    {
        $subnet = $this->makeSubnet();
        $data = $this->apiRequest('POST', '/api/virtual-ips', [
            'label'      => 'vip-with-ip',
            'subnet_id'  => $subnet->getId(),
            'ip_address' => '10.78.0.1',
        ]);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame('10.78.0.1', $data['ip_address']);
    }

    public function testCreateWithAutoIpv4(): void
    {
        $subnet = $this->makeSubnet();
        $data = $this->apiRequest('POST', '/api/virtual-ips', [
            'label'      => 'auto-ip-vip',
            'subnet_id'  => $subnet->getId(),
            'ip_address' => 'auto',
        ]);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertNotNull($data['ip_address']);
        $this->assertStringStartsWith('10.78.0.', $data['ip_address']);
    }

    public function testCreateWithInvalidIpRejected(): void
    {
        $subnet = $this->makeSubnet();
        $this->apiRequest('POST', '/api/virtual-ips', [
            'label'      => 'bad-ip-vip',
            'subnet_id'  => $subnet->getId(),
            'ip_address' => '999.999.999.999',
        ]);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Update
    // -------------------------------------------------------------------------

    public function testUpdate(): void
    {
        $vip = $this->makeVip($this->makeSubnet());
        $data = $this->apiRequest('PATCH', "/api/virtual-ips/{$vip->getId()}", [
            'label'    => 'patched-vip',
            'protocol' => 'active_gateway',
            'vrid'     => 99,
            'notes'    => 'test note',
        ]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('patched-vip', $data['label']);
        $this->assertSame('active_gateway', $data['protocol']);
        $this->assertSame(99, $data['vrid']);
        $this->assertSame('test note', $data['notes']);
    }

    public function testUpdateAcceptsAnycastProtocol(): void
    {
        $vip = $this->makeVip($this->makeSubnet());
        $data = $this->apiRequest('PATCH', "/api/virtual-ips/{$vip->getId()}", [
            'protocol' => 'anycast',
        ]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('anycast', $data['protocol']);
    }

    public function testUpdateLabelCannotBeEmpty(): void
    {
        $vip = $this->makeVip($this->makeSubnet());
        $this->apiRequest('PATCH', "/api/virtual-ips/{$vip->getId()}", ['label' => '']);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testUpdateDeletedReturns404(): void
    {
        $vip = $this->makeVip($this->makeSubnet());
        $vip->softDelete();
        $this->em->flush();

        $this->apiRequest('PATCH', "/api/virtual-ips/{$vip->getId()}", ['label' => 'x']);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Delete & Restore
    // -------------------------------------------------------------------------

    public function testSoftDelete(): void
    {
        $vip = $this->makeVip($this->makeSubnet());
        $id  = $vip->getId();

        $this->apiRequest('DELETE', "/api/virtual-ips/{$id}");
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        $this->apiRequest('GET', "/api/virtual-ips/{$id}");
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testSoftDeleteIdempotent(): void
    {
        $vip = $this->makeVip($this->makeSubnet());
        $vip->softDelete();
        $this->em->flush();

        $this->apiRequest('DELETE', "/api/virtual-ips/{$vip->getId()}");
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    public function testRestore(): void
    {
        $vip = $this->makeVip($this->makeSubnet());
        $vip->softDelete();
        $this->em->flush();

        $data = $this->apiRequest('POST', "/api/virtual-ips/{$vip->getId()}/restore");
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertNull($data['deleted_at']);
    }

    // -------------------------------------------------------------------------
    // Member interfaces
    // -------------------------------------------------------------------------

    public function testAddMember(): void
    {
        $subnet = $this->makeSubnet();
        $vip    = $this->makeVip($subnet);
        $iface  = $this->makeInterface($subnet);

        $data = $this->apiRequest('POST', "/api/virtual-ips/{$vip->getId()}/members", [
            'interface_id' => $iface->getId(),
        ]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertContains($iface->getId(), $data['member_interface_ids']);
    }

    public function testAddMemberIdempotent(): void
    {
        $subnet = $this->makeSubnet();
        $vip    = $this->makeVip($subnet);
        $iface  = $this->makeInterface($subnet);

        $this->apiRequest('POST', "/api/virtual-ips/{$vip->getId()}/members", ['interface_id' => $iface->getId()]);
        $data = $this->apiRequest('POST', "/api/virtual-ips/{$vip->getId()}/members", ['interface_id' => $iface->getId()]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertCount(1, $data['member_interface_ids']);
    }

    public function testAddMemberRequiresInterfaceId(): void
    {
        $vip = $this->makeVip($this->makeSubnet());
        $this->apiRequest('POST', "/api/virtual-ips/{$vip->getId()}/members", []);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testAddMemberToDeletedVipReturns404(): void
    {
        $subnet = $this->makeSubnet();
        $vip    = $this->makeVip($subnet);
        $iface  = $this->makeInterface($subnet);
        $vip->softDelete();
        $this->em->flush();

        $this->apiRequest('POST', "/api/virtual-ips/{$vip->getId()}/members", ['interface_id' => $iface->getId()]);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testRemoveMember(): void
    {
        $subnet = $this->makeSubnet();
        $vip    = $this->makeVip($subnet);
        $iface  = $this->makeInterface($subnet);
        $vip->addMemberInterface($iface);
        $this->em->flush();

        $this->apiRequest('DELETE', "/api/virtual-ips/{$vip->getId()}/members/{$iface->getId()}");
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        $this->em->refresh($vip);
        $this->assertFalse($vip->getMemberInterfaces()->contains($iface));
    }

    public function testRemoveMemberIdempotent(): void
    {
        $vip   = $this->makeVip($this->makeSubnet());
        $iface = $this->makeInterface($vip->getSubnet());

        $this->apiRequest('DELETE', "/api/virtual-ips/{$vip->getId()}/members/{$iface->getId()}");
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }
}
