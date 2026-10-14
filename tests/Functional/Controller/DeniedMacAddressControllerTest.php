<?php

namespace App\Tests\Functional\Controller;

use App\Entity\DeniedMacAddress;
use App\Tests\Functional\AppWebTestCase;

class DeniedMacAddressControllerTest extends AppWebTestCase
{
    public function testIndexLoads(): void
    {
        $this->client->request('GET', '/denied-mac-addresses');
        $this->assertResponseIsSuccessful();
    }

    public function testNewFormLoads(): void
    {
        $this->client->request('GET', '/denied-mac-addresses/new');
        $this->assertResponseIsSuccessful();
    }

    public function testCreate(): void
    {
        $crawler = $this->client->request('GET', '/denied-mac-addresses/new');
        $this->client->submit($crawler->filter('form')->form(), [
            'denied_mac_address[macAddress]' => 'aa:bb:cc:dd:ee:01',
        ]);
        $this->assertResponseRedirects();
    }

    public function testEditFormLoads(): void
    {
        $denied = (new DeniedMacAddress())->setMacAddress('aa:bb:cc:dd:ee:02');
        $this->em->persist($denied);
        $this->em->flush();

        $this->client->request('GET', "/denied-mac-addresses/{$denied->getId()}/edit");
        $this->assertResponseIsSuccessful();
    }

    public function testUpdate(): void
    {
        $denied = (new DeniedMacAddress())->setMacAddress('aa:bb:cc:dd:ee:03');
        $this->em->persist($denied);
        $this->em->flush();

        $crawler = $this->client->request('GET', "/denied-mac-addresses/{$denied->getId()}/edit");
        $this->client->submit($crawler->filter('form')->form(), [
            'denied_mac_address[macAddress]' => 'aa:bb:cc:dd:ee:04',
        ]);
        $this->assertResponseRedirects();
    }

    public function testDelete(): void
    {
        $denied = (new DeniedMacAddress())->setMacAddress('aa:bb:cc:dd:ee:05');
        $this->em->persist($denied);
        $this->em->flush();

        $id      = $denied->getId();
        $crawler = $this->client->request('GET', '/denied-mac-addresses');
        $this->client->submit(
            $crawler->filter('form[action="/denied-mac-addresses/' . $id . '/delete"]')->form()
        );
        $this->assertResponseRedirects();
    }
}
