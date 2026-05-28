<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Host;
use App\Tests\Functional\AppWebTestCase;

class HostControllerTest extends AppWebTestCase
{
    public function testIndexLoads(): void
    {
        $this->client->request('GET', '/hosts');
        $this->assertResponseIsSuccessful();
    }

    public function testNewFormLoads(): void
    {
        $this->client->request('GET', '/hosts/new');
        $this->assertResponseIsSuccessful();
    }

    public function testCreate(): void
    {
        $crawler = $this->client->request('GET', '/hosts/new');
        $this->client->submit($crawler->filter('form')->form(), [
            'host[name]' => 'new-functional-host',
        ]);
        $this->assertResponseRedirects();
    }

    public function testShowLoads(): void
    {
        $host = (new Host())->setName('show-host');
        $this->em->persist($host);
        $this->em->flush();

        $this->client->request('GET', "/hosts/{$host->getId()}");
        $this->assertResponseIsSuccessful();
    }

    public function testEditFormLoads(): void
    {
        $host = (new Host())->setName('edit-host');
        $this->em->persist($host);
        $this->em->flush();

        $this->client->request('GET', "/hosts/{$host->getId()}/edit");
        $this->assertResponseIsSuccessful();
    }

    public function testUpdate(): void
    {
        $host = (new Host())->setName('update-host');
        $this->em->persist($host);
        $this->em->flush();

        $crawler = $this->client->request('GET', "/hosts/{$host->getId()}/edit");
        $this->client->submit($crawler->filter('form')->form(), [
            'host[name]' => 'updated-host',
        ]);
        $this->assertResponseRedirects();
    }

    public function testDelete(): void
    {
        $host = (new Host())->setName('delete-host');
        $this->em->persist($host);
        $this->em->flush();

        $id      = $host->getId();
        $crawler = $this->client->request('GET', "/hosts/{$id}");
        $this->client->submit(
            $crawler->filter('form[action="/hosts/' . $id . '/delete"]')->form()
        );
        $this->assertResponseRedirects();
    }

    public function testSoftDeletedHostNotVisibleInIndex(): void
    {
        $host = (new Host())->setName('soft-deleted-host');
        $host->softDelete();
        $this->em->persist($host);
        $this->em->flush();

        $this->client->request('GET', '/hosts');
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('soft-deleted-host', $this->client->getResponse()->getContent());
    }
}
