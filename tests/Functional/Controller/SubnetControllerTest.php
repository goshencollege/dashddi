<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Subnet;
use App\Tests\Functional\AppWebTestCase;

class SubnetControllerTest extends AppWebTestCase
{
    public function testIndexLoads(): void
    {
        $this->client->request('GET', '/subnets');
        $this->assertResponseIsSuccessful();
    }

    public function testNewFormLoads(): void
    {
        $this->client->request('GET', '/subnets/new');
        $this->assertResponseIsSuccessful();
    }

    public function testCreate(): void
    {
        $crawler = $this->client->request('GET', '/subnets/new');
        $this->client->submit($crawler->filter('form')->form(), [
            'subnet[name]'     => 'New Functional Subnet',
            'subnet[ipv4Cidr]' => '10.99.0.0/24',
        ]);
        $this->assertResponseRedirects();
    }

    public function testShowLoads(): void
    {
        $subnet = (new Subnet())->setName('Show Subnet')->setIpv4Cidr('10.88.0.0/24');
        $this->em->persist($subnet);
        $this->em->flush();

        $this->client->request('GET', "/subnets/{$subnet->getId()}");
        $this->assertResponseIsSuccessful();
    }

    public function testEditFormLoads(): void
    {
        $subnet = (new Subnet())->setName('Edit Subnet')->setIpv4Cidr('10.87.0.0/24');
        $this->em->persist($subnet);
        $this->em->flush();

        $this->client->request('GET', "/subnets/{$subnet->getId()}/edit");
        $this->assertResponseIsSuccessful();
    }

    public function testUpdate(): void
    {
        $subnet = (new Subnet())->setName('Update Subnet')->setIpv4Cidr('10.86.0.0/24');
        $this->em->persist($subnet);
        $this->em->flush();

        $crawler = $this->client->request('GET', "/subnets/{$subnet->getId()}/edit");
        $this->client->submit($crawler->filter('form')->form(), [
            'subnet[name]'     => 'Updated Subnet',
            'subnet[ipv4Cidr]' => '10.86.0.0/24',
        ]);
        $this->assertResponseRedirects();
    }

    public function testDelete(): void
    {
        $subnet = (new Subnet())->setName('Delete Subnet');
        $this->em->persist($subnet);
        $this->em->flush();

        $id      = $subnet->getId();
        $crawler = $this->client->request('GET', '/subnets');
        $this->client->submit(
            $crawler->filter('form[action="/subnets/' . $id . '/delete"]')->form()
        );
        $this->assertResponseRedirects();
    }

    // -------------------------------------------------------------------------
    // Terminal subnet overlap validation
    // -------------------------------------------------------------------------

    public function testCreateTerminalSubnetBlockedByIpv4Overlap(): void
    {
        $existing = (new Subnet())->setName('Existing')->setIpv4Cidr('10.1.0.0/24');
        $this->em->persist($existing);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/subnets/new');
        $this->client->submit($crawler->filter('form')->form(), [
            'subnet[name]'     => 'Conflicting',
            'subnet[ipv4Cidr]' => '10.1.0.0/24',
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertStringContainsString('overlaps', $this->client->getResponse()->getContent());
    }

    public function testEditTerminalSubnetBlockedByIpv4Overlap(): void
    {
        $existing = (new Subnet())->setName('Existing')->setIpv4Cidr('10.2.0.0/24');
        $this->em->persist($existing);
        $target = (new Subnet())->setName('Target')->setIpv4Cidr('10.3.0.0/24');
        $this->em->persist($target);
        $this->em->flush();

        $crawler = $this->client->request('GET', "/subnets/{$target->getId()}/edit");
        $this->client->submit($crawler->filter('form')->form(), [
            'subnet[name]'     => 'Target',
            'subnet[ipv4Cidr]' => '10.2.0.0/24',
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertStringContainsString('overlaps', $this->client->getResponse()->getContent());
    }

    public function testCreateWithOverlappingInlineBlocksIsBlocked(): void
    {
        $crawler = $this->client->request('GET', '/subnets/new');
        $this->client->submit($crawler->filter('form')->form(), [
            'subnet[name]'                  => 'Block Overlap Subnet',
            'subnet[ipv4Cidr]'              => '10.4.0.0/24',
            'subnet[reservedBlock][startIp]' => '10.4.0.10',
            'subnet[reservedBlock][endIp]'   => '10.4.0.50',
            'subnet[fixedBlock][startIp]'    => '10.4.0.40',
            'subnet[fixedBlock][endIp]'      => '10.4.0.60',
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertStringContainsString('overlap', $this->client->getResponse()->getContent());
    }
}
