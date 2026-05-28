<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Vrf;
use App\Tests\Functional\AppWebTestCase;

class VrfControllerTest extends AppWebTestCase
{
    public function testIndexLoads(): void
    {
        $this->client->request('GET', '/vrfs');
        $this->assertResponseIsSuccessful();
    }

    public function testNewFormLoads(): void
    {
        $this->client->request('GET', '/vrfs/new');
        $this->assertResponseIsSuccessful();
    }

    public function testCreate(): void
    {
        $crawler = $this->client->request('GET', '/vrfs/new');
        $this->client->submit($crawler->filter('form')->form(), [
            'vrf[name]' => 'test-vrf-new',
        ]);
        $this->assertResponseRedirects();
    }

    public function testEditFormLoads(): void
    {
        $vrf = (new Vrf())->setName('edit-vrf');
        $this->em->persist($vrf);
        $this->em->flush();

        $this->client->request('GET', "/vrfs/{$vrf->getId()}/edit");
        $this->assertResponseIsSuccessful();
    }

    public function testUpdate(): void
    {
        $vrf = (new Vrf())->setName('update-vrf');
        $this->em->persist($vrf);
        $this->em->flush();

        $crawler = $this->client->request('GET', "/vrfs/{$vrf->getId()}/edit");
        $this->client->submit($crawler->filter('form')->form(), [
            'vrf[name]' => 'updated-vrf',
        ]);
        $this->assertResponseRedirects();
    }

    public function testDelete(): void
    {
        $vrf = (new Vrf())->setName('delete-vrf');
        $this->em->persist($vrf);
        $this->em->flush();

        $id      = $vrf->getId();
        $crawler = $this->client->request('GET', '/vrfs');
        $this->client->submit(
            $crawler->filter('form[action="/vrfs/' . $id . '/delete"]')->form()
        );
        $this->assertResponseRedirects();
    }
}
