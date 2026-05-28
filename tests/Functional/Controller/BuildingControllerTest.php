<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Building;
use App\Tests\Functional\AppWebTestCase;

class BuildingControllerTest extends AppWebTestCase
{
    public function testIndexLoads(): void
    {
        $this->client->request('GET', '/buildings');
        $this->assertResponseIsSuccessful();
    }

    public function testNewFormLoads(): void
    {
        $this->client->request('GET', '/buildings/new');
        $this->assertResponseIsSuccessful();
    }

    public function testCreate(): void
    {
        $crawler = $this->client->request('GET', '/buildings/new');
        $this->client->submit($crawler->filter('form')->form(), [
            'building[name]' => 'New Test Building',
        ]);
        $this->assertResponseRedirects();
    }

    public function testEditFormLoads(): void
    {
        $building = (new Building())->setName('Edit Building');
        $this->em->persist($building);
        $this->em->flush();

        $this->client->request('GET', "/buildings/{$building->getId()}/edit");
        $this->assertResponseIsSuccessful();
    }

    public function testUpdate(): void
    {
        $building = (new Building())->setName('Update Building');
        $this->em->persist($building);
        $this->em->flush();

        $crawler = $this->client->request('GET', "/buildings/{$building->getId()}/edit");
        $this->client->submit($crawler->filter('form')->form(), [
            'building[name]' => 'Updated Building',
        ]);
        $this->assertResponseRedirects();
    }

    public function testDelete(): void
    {
        $building = (new Building())->setName('Delete Building');
        $this->em->persist($building);
        $this->em->flush();

        $id      = $building->getId();
        $crawler = $this->client->request('GET', '/buildings');
        $this->client->submit(
            $crawler->filter('form[action="/buildings/' . $id . '/delete"]')->form()
        );
        $this->assertResponseRedirects();
    }
}
