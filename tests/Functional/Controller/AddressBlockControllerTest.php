<?php

namespace App\Tests\Functional\Controller;

use App\Entity\AddressBlock;
use App\Entity\Subnet;
use App\Enum\BlockType;
use App\Tests\Functional\AppWebTestCase;

class AddressBlockControllerTest extends AppWebTestCase
{
    private function makeSubnet(string $cidr = '10.99.0.0/24'): Subnet
    {
        $subnet = (new Subnet())->setName('Test Subnet')->setIpv4Cidr($cidr);
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

    public function testNewFormLoads(): void
    {
        $subnet = $this->makeSubnet();
        $this->client->request('GET', "/subnet/{$subnet->getId()}/blocks/new");
        $this->assertResponseIsSuccessful();
    }

    public function testCreateValidBlock(): void
    {
        $subnet  = $this->makeSubnet();
        $crawler = $this->client->request('GET', "/subnet/{$subnet->getId()}/blocks/new");
        $this->client->submit($crawler->filter('form')->form(), [
            'address_block[type]'    => 'reserved',
            'address_block[startIp]' => '10.99.0.10',
            'address_block[endIp]'   => '10.99.0.50',
        ]);
        $this->assertResponseRedirects();
    }

    public function testCreateBlockBlockedByOverlap(): void
    {
        $subnet = $this->makeSubnet();
        $this->makeBlock($subnet, '10.99.0.10', '10.99.0.50');

        $crawler = $this->client->request('GET', "/subnet/{$subnet->getId()}/blocks/new");
        $this->client->submit($crawler->filter('form')->form(), [
            'address_block[type]'    => 'fixed',
            'address_block[startIp]' => '10.99.0.40',
            'address_block[endIp]'   => '10.99.0.60',
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertStringContainsString('overlaps', $this->client->getResponse()->getContent());
    }

    public function testEditFormLoads(): void
    {
        $subnet = $this->makeSubnet();
        $block  = $this->makeBlock($subnet, '10.99.0.10', '10.99.0.50');
        $this->client->request('GET', "/blocks/{$block->getId()}/edit");
        $this->assertResponseIsSuccessful();
    }

    public function testEditBlockBlockedByOverlap(): void
    {
        $subnet    = $this->makeSubnet();
        $existing  = $this->makeBlock($subnet, '10.99.0.10', '10.99.0.50');
        $toEdit    = $this->makeBlock($subnet, '10.99.0.60', '10.99.0.80', BlockType::Fixed);

        $crawler = $this->client->request('GET', "/blocks/{$toEdit->getId()}/edit");
        $this->client->submit($crawler->filter('form')->form(), [
            'address_block[type]'    => 'fixed',
            'address_block[startIp]' => '10.99.0.40',
            'address_block[endIp]'   => '10.99.0.70',
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertStringContainsString('overlaps', $this->client->getResponse()->getContent());
    }
}
