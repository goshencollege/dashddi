<?php

namespace App\Tests\Functional\Api;

use App\Entity\Host;
use App\Entity\IpAddress;
use App\Entity\NetworkInterface;
use App\Entity\Subnet;
use App\Entity\VirtualIp;
use App\Enum\VirtualIpProtocol;
use App\Tests\Functional\AppWebTestCase;

class InterfaceApiControllerTest extends AppWebTestCase
{
    private function makeSubnet(string $cidr): Subnet
    {
        $subnet = (new Subnet())->setName("test $cidr")->setIpv4Cidr($cidr);
        $this->em->persist($subnet);
        $this->em->flush();
        return $subnet;
    }

    private function makeInterface(Subnet $subnet, string $mac = 'aa:bb:cc:dd:ee:02'): NetworkInterface
    {
        $host  = (new Host())->setName('api-test-host');
        $this->em->persist($host);
        $iface = (new NetworkInterface())->setHost($host)->setMacAddress($mac)->setSubnet($subnet);
        $this->em->persist($iface);
        $this->em->flush();
        return $iface;
    }

    private function makeVipWithIp(Subnet $subnet, string $address): VirtualIp
    {
        $ip  = (new IpAddress())->setAddress($address)->setSubnet($subnet);
        $vip = (new VirtualIp())
            ->setLabel('test-vip')
            ->setProtocol(VirtualIpProtocol::Vrrp)
            ->setSubnet($subnet)
            ->setIpAddress($ip);
        $this->em->persist($vip);
        $this->em->flush();
        return $vip;
    }

    public function testUpdateSubnetChangeUnlinksVipWhoseIpIsNotInNewSubnet(): void
    {
        $subnetA = $this->makeSubnet('10.60.0.0/24');
        $subnetB = $this->makeSubnet('10.61.0.0/24');
        $iface   = $this->makeInterface($subnetA);
        $vip     = $this->makeVipWithIp($subnetA, '10.60.0.100');
        $vip->addMemberInterface($iface);
        $this->em->flush();

        $this->apiRequest('PATCH', "/api/interfaces/{$iface->getId()}", [
            'subnet_id' => $subnetB->getId(),
        ]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $vip = $this->em->find(VirtualIp::class, $vip->getId());
        $this->assertCount(0, $vip->getMemberInterfaces(), 'VIP should be unlinked when its IP is outside the new subnet');
    }

    public function testUpdateSubnetChangeKeepsVipWhoseIpIsStillValidInNewSubnet(): void
    {
        $subnetA = $this->makeSubnet('10.62.0.0/24');
        $subnetB = $this->makeSubnet('10.63.0.0/24');
        $iface   = $this->makeInterface($subnetA);
        // VIP belongs to subnetB and its IP is valid there
        $vip     = $this->makeVipWithIp($subnetB, '10.63.0.100');
        $vip->addMemberInterface($iface);
        $this->em->flush();

        $this->apiRequest('PATCH', "/api/interfaces/{$iface->getId()}", [
            'subnet_id' => $subnetB->getId(),
        ]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $vip = $this->em->find(VirtualIp::class, $vip->getId());
        $this->assertCount(1, $vip->getMemberInterfaces(), 'VIP should stay linked when its IP is valid in the new subnet');
    }
}
