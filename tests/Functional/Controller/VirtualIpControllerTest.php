<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Subnet;
use App\Entity\VirtualIp;
use App\Enum\VirtualIpProtocol;
use App\Tests\Functional\AppWebTestCase;

class VirtualIpControllerTest extends AppWebTestCase
{
    private function makeSubnet(): Subnet
    {
        $subnet = (new Subnet())->setName('VIP Test Subnet')->setIpv4Cidr('10.77.0.0/24');
        $this->em->persist($subnet);
        $this->em->flush();
        return $subnet;
    }

    private function makeVip(Subnet $subnet): VirtualIp
    {
        $vip = new VirtualIp();
        $vip->setLabel('test-vip');
        $vip->setProtocol(VirtualIpProtocol::Vrrp);
        $vip->setSubnet($subnet);
        $this->em->persist($vip);
        $this->em->flush();
        return $vip;
    }

    public function testNewFormLoads(): void
    {
        $subnet = $this->makeSubnet();
        $this->client->request('GET', "/subnets/{$subnet->getId()}/virtual-ips/new");
        $this->assertResponseIsSuccessful();
    }

    public function testCreate(): void
    {
        $subnet  = $this->makeSubnet();
        $crawler = $this->client->request('GET', "/subnets/{$subnet->getId()}/virtual-ips/new");
        $this->client->submit($crawler->filter('form')->form(), [
            'virtual_ip[label]'          => 'gateway-vip',
            'virtual_ip[protocol]'       => VirtualIpProtocol::Vrrp->value,
            'virtual_ip[vrid]'           => '1',
            'virtual_ip[ipv4Assignment]' => 'none',
            'virtual_ip[ipv6Assignment]' => 'none',
        ]);
        $this->assertResponseRedirects();
    }

    public function testShowLoads(): void
    {
        $vip = $this->makeVip($this->makeSubnet());
        $this->client->request('GET', "/virtual-ips/{$vip->getId()}");
        $this->assertResponseIsSuccessful();
    }

    public function testEditFormLoads(): void
    {
        $vip = $this->makeVip($this->makeSubnet());
        $this->client->request('GET', "/virtual-ips/{$vip->getId()}/edit");
        $this->assertResponseIsSuccessful();
    }

    public function testUpdate(): void
    {
        $vip     = $this->makeVip($this->makeSubnet());
        $crawler = $this->client->request('GET', "/virtual-ips/{$vip->getId()}/edit");
        $this->client->submit($crawler->filter('form')->form(), [
            'virtual_ip[label]'          => 'updated-vip',
            'virtual_ip[protocol]'       => VirtualIpProtocol::Hsrp->value,
            'virtual_ip[ipv4Assignment]' => 'keep',
            'virtual_ip[ipv6Assignment]' => 'keep',
        ]);
        $this->assertResponseRedirects();
    }

    public function testSoftDelete(): void
    {
        $vip    = $this->makeVip($this->makeSubnet());
        $id     = $vip->getId();
        $subnetId = $vip->getSubnet()->getId();

        $crawler = $this->client->request('GET', "/virtual-ips/{$id}");
        $this->client->submit(
            $crawler->filter("form[action='/virtual-ips/{$id}/delete']")->form()
        );
        $this->assertResponseRedirects("/subnets/{$subnetId}");

        $this->em->clear();
        $deleted = $this->em->find(VirtualIp::class, $id);
        $this->assertTrue($deleted->isDeleted());
    }

    public function testRestore(): void
    {
        $vip = $this->makeVip($this->makeSubnet());
        $vip->softDelete();
        $this->em->flush();
        $id = $vip->getId();

        $crawler = $this->client->request('GET', "/virtual-ips/{$id}");
        $this->client->submit(
            $crawler->filter("form[action='/virtual-ips/{$id}/restore']")->form()
        );
        $this->assertResponseRedirects("/virtual-ips/{$id}");

        $this->em->clear();
        $restored = $this->em->find(VirtualIp::class, $id);
        $this->assertFalse($restored->isDeleted());
    }

    public function testCreateWithSpecifiedIpv4(): void
    {
        $subnet  = $this->makeSubnet();
        $crawler = $this->client->request('GET', "/subnets/{$subnet->getId()}/virtual-ips/new");
        $this->client->submit($crawler->filter('form')->form(), [
            'virtual_ip[label]'           => 'vip-with-ip',
            'virtual_ip[protocol]'        => VirtualIpProtocol::Vrrp->value,
            'virtual_ip[ipv4Assignment]'  => 'select',
            'virtual_ip[ipv4AddressInput]'=> '10.77.0.1',
            'virtual_ip[ipv6Assignment]'  => 'none',
        ]);
        $this->assertResponseRedirects();
    }
}
