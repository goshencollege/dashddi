<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Host;
use App\Entity\IpAddress;
use App\Entity\NetworkInterface;
use App\Entity\Subnet;
use App\Entity\VirtualIp;
use App\Enum\VirtualIpProtocol;
use App\Tests\Functional\AppWebTestCase;

class InterfaceControllerTest extends AppWebTestCase
{
    private function makeSubnet(string $cidr): Subnet
    {
        $subnet = (new Subnet())->setName("test $cidr")->setIpv4Cidr($cidr);
        $this->em->persist($subnet);
        $this->em->flush();
        return $subnet;
    }

    private function makeInterface(Subnet $subnet, string $mac = 'aa:bb:cc:dd:ee:01'): NetworkInterface
    {
        $host  = (new Host())->setName('test-host');
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

    public function testEditSubnetChangeUnlinksVipWhoseIpIsNotInNewSubnet(): void
    {
        $subnetA = $this->makeSubnet('10.50.0.0/24');
        $subnetB = $this->makeSubnet('10.51.0.0/24');
        $iface   = $this->makeInterface($subnetA);
        $vip     = $this->makeVipWithIp($subnetA, '10.50.0.100');
        $vip->addMemberInterface($iface);
        $this->em->flush();

        $crawler = $this->client->request('GET', "/interfaces/{$iface->getId()}/edit");
        $this->client->submit($crawler->filter('form')->form(), [
            'network_interface[subnet]'         => $subnetB->getId(),
            'network_interface[ipv4Assignment]' => 'keep',
            'network_interface[ipv6Assignment]' => 'keep',
        ]);
        $this->assertResponseRedirects();

        $this->em->clear();
        $vip = $this->em->find(VirtualIp::class, $vip->getId());
        $this->assertCount(0, $vip->getMemberInterfaces(), 'VIP should be unlinked when its IP is outside the new subnet');
    }

    public function testEditSubnetChangeKeepsVipWhoseIpIsStillValidInNewSubnet(): void
    {
        $subnetA = $this->makeSubnet('10.52.0.0/24');
        $subnetB = $this->makeSubnet('10.53.0.0/24');
        $iface   = $this->makeInterface($subnetA);
        // VIP belongs to subnetB and its IP is valid there
        $vip     = $this->makeVipWithIp($subnetB, '10.53.0.100');
        $vip->addMemberInterface($iface);
        $this->em->flush();

        $crawler = $this->client->request('GET', "/interfaces/{$iface->getId()}/edit");
        $this->client->submit($crawler->filter('form')->form(), [
            'network_interface[subnet]'         => $subnetB->getId(),
            'network_interface[ipv4Assignment]' => 'keep',
            'network_interface[ipv6Assignment]' => 'keep',
        ]);
        $this->assertResponseRedirects();

        $this->em->clear();
        $vip = $this->em->find(VirtualIp::class, $vip->getId());
        $this->assertCount(1, $vip->getMemberInterfaces(), 'VIP should stay linked when its IP is valid in the new subnet');
    }

    public function testBulkEditSubnetChangeUnlinksVipWhoseIpIsNotInNewSubnet(): void
    {
        $subnetA = $this->makeSubnet('10.54.0.0/24');
        $subnetB = $this->makeSubnet('10.55.0.0/24');
        $iface   = $this->makeInterface($subnetA);
        $vip     = $this->makeVipWithIp($subnetA, '10.54.0.100');
        $vip->addMemberInterface($iface);
        $this->em->flush();

        // Load the host list to establish a session and generate the bulk_interfaces CSRF token.
        // The token is rendered inline in the page JavaScript, so extract it for the POST request.
        $this->client->request('GET', '/hosts');
        $html  = $this->client->getResponse()->getContent();
        preg_match("/ifaceSelected\], _token: '([^']+)'/", $html, $m);
        $token = $m[1] ?? '';

        $this->client->request(
            'POST',
            '/interfaces/bulk',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                '_token'         => $token,
                'action'         => 'edit',
                'ids'            => [$iface->getId()],
                'subnet_id'      => $subnetB->getId(),
                'ipv4Assignment' => 'keep',
                'ipv6Assignment' => 'keep',
            ])
        );
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $vip = $this->em->find(VirtualIp::class, $vip->getId());
        $this->assertCount(0, $vip->getMemberInterfaces(), 'VIP should be unlinked by bulk subnet change when its IP is outside the new subnet');
    }

    public function testEditFormPrefillsDuidInNetworkctlFormat(): void
    {
        $subnet = $this->makeSubnet('10.56.0.0/24');
        $iface  = $this->makeInterface($subnet);
        $iface->getHost()->setDuid('00020000ab11cc5702f3da97b768');
        $this->em->flush();

        $crawler = $this->client->request('GET', "/interfaces/{$iface->getId()}/edit");
        $this->assertSame(
            'DUID-EN/Vendor:0000ab11cc5702f3da97b768',
            $crawler->filter('form')->form()->get('network_interface[duid]')->getValue()
        );
    }

    public function testEditRoundTripsDuidPastedInNetworkctlFormatThroughToHost(): void
    {
        $subnet = $this->makeSubnet('10.57.0.0/24');
        $iface  = $this->makeInterface($subnet);
        $hostId = $iface->getHost()->getId();

        $crawler = $this->client->request('GET', "/interfaces/{$iface->getId()}/edit");
        $this->client->submit($crawler->filter('form')->form(), [
            'network_interface[duid]'           => 'DUID-EN/Vendor:0000ab11cc5702f3da97b768',
            'network_interface[ipv4Assignment]' => 'keep',
            'network_interface[ipv6Assignment]' => 'keep',
        ]);
        $this->assertResponseRedirects();

        $this->em->clear();
        $host = $this->em->find(Host::class, $hostId);
        $this->assertSame('00:02:00:00:ab:11:cc:57:02:f3:da:97:b7:68', $host->getDuid());
    }
}
