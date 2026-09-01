<?php

namespace App\Tests\Functional\Api;

use App\Entity\Domain;
use App\Entity\DnsView;
use App\Entity\Host;
use App\Entity\NetworkInterface;
use App\Entity\Subnet;
use App\Tests\Functional\AppWebTestCase;

class DomainRecordApiControllerTest extends AppWebTestCase
{
    private function makeView(string $name): DnsView
    {
        $view = (new DnsView())->setName($name);
        $this->em->persist($view);
        $this->em->flush();
        return $view;
    }

    private function makeDomain(string $name, array $views = []): Domain
    {
        $domain = (new Domain())->setName($name);
        foreach ($views as $view) {
            $domain->addView($view);
        }
        $this->em->persist($domain);
        $this->em->flush();
        return $domain;
    }

    private function makeSubnet(string $cidr, array $views = []): Subnet
    {
        $subnet = (new Subnet())->setName("test $cidr")->setIpv4Cidr($cidr);
        foreach ($views as $view) {
            $subnet->addView($view);
        }
        $this->em->persist($subnet);
        $this->em->flush();
        return $subnet;
    }

    private function makeInterface(Subnet $subnet, string $mac = 'aa:bb:cc:dd:ee:03'): NetworkInterface
    {
        $host  = (new Host())->setName('domain-record-api-test-host');
        $this->em->persist($host);
        $iface = (new NetworkInterface())->setHost($host)->setMacAddress($mac)->setSubnet($subnet);
        $this->em->persist($iface);
        $this->em->flush();
        return $iface;
    }

    public function testCreateWithAllViewAttachesIntersectionOfDomainAndSubnetViews(): void
    {
        $viewA = $this->makeView('all-view-a');
        $viewB = $this->makeView('all-view-b');
        $domain = $this->makeDomain('all-view.example', [$viewA, $viewB]);
        $subnet = $this->makeSubnet('10.70.0.0/24', [$viewA]);
        $iface  = $this->makeInterface($subnet);

        $data = $this->apiRequest('POST', '/api/domain-records', [
            'hostname'     => 'host1',
            'type'         => 'A',
            'interface_id' => $iface->getId(),
            'domain_id'    => $domain->getId(),
            'all_views'    => true,
        ]);

        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame([$viewA->getId()], $data['view_ids']);
    }

    public function testCreateWithAllViewIgnoresExplicitViewIds(): void
    {
        $viewA = $this->makeView('all-view-ignore-a');
        $viewB = $this->makeView('all-view-ignore-b');
        $domain = $this->makeDomain('all-view-ignore.example', [$viewA]);

        $data = $this->apiRequest('POST', '/api/domain-records', [
            'hostname'  => 'host2',
            'type'      => 'TXT',
            'domain_id' => $domain->getId(),
            'value'     => 'hello',
            'all_views' => true,
            'view_ids'  => [$viewB->getId()],
        ]);

        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame([$viewA->getId()], $data['view_ids']);
    }

    public function testCreateWithoutAllViewUsesExplicitViewIds(): void
    {
        $viewA = $this->makeView('explicit-view-a');
        $domain = $this->makeDomain('explicit-view.example');

        $data = $this->apiRequest('POST', '/api/domain-records', [
            'hostname'  => 'host3',
            'type'      => 'TXT',
            'domain_id' => $domain->getId(),
            'value'     => 'hello',
            'view_ids'  => [$viewA->getId()],
        ]);

        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame([$viewA->getId()], $data['view_ids']);
    }

    public function testUpdateWithAllViewRecomputesUsingJustUpdatedInterface(): void
    {
        $viewA = $this->makeView('update-all-view-a');
        $viewB = $this->makeView('update-all-view-b');
        $domain = $this->makeDomain('update-all-view.example', [$viewA, $viewB]);
        $subnet = $this->makeSubnet('10.71.0.0/24', [$viewB]);
        $iface  = $this->makeInterface($subnet, 'aa:bb:cc:dd:ee:04');

        // Domain-only TXT record: no subnet in play, so all_views resolves to every domain view.
        $data = $this->apiRequest('POST', '/api/domain-records', [
            'hostname'  => 'host4',
            'type'      => 'TXT',
            'domain_id' => $domain->getId(),
            'value'     => 'hello',
            'all_views' => true,
        ]);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame([$viewA->getId(), $viewB->getId()], $data['view_ids']);

        // Linking it to an interface on a subnet narrows all_views down to the intersection.
        $updated = $this->apiRequest('PATCH', "/api/domain-records/{$data['id']}", [
            'interface_id' => $iface->getId(),
            'all_views'    => true,
        ]);

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame([$viewB->getId()], $updated['view_ids']);
    }
}
