<?php

namespace App\Tests\Functional\Controller;

use App\Entity\DnsView;
use App\Tests\Functional\AppWebTestCase;

class DnsViewControllerTest extends AppWebTestCase
{
    public function testIndexLoads(): void
    {
        $this->client->request('GET', '/dns-views');
        $this->assertResponseIsSuccessful();
    }

    public function testNewFormLoads(): void
    {
        $this->client->request('GET', '/dns-views/new');
        $this->assertResponseIsSuccessful();
    }

    public function testCreate(): void
    {
        $crawler = $this->client->request('GET', '/dns-views/new');
        $this->client->submit($crawler->filter('form')->form(), [
            'dns_view[name]' => 'new-functional-view',
        ]);
        $this->assertResponseRedirects();
    }

    public function testEditFormLoads(): void
    {
        $view = (new DnsView())->setName('edit-dns-view');
        $this->em->persist($view);
        $this->em->flush();

        $this->client->request('GET', "/dns-views/{$view->getId()}/edit");
        $this->assertResponseIsSuccessful();
    }

    public function testUpdate(): void
    {
        $view = (new DnsView())->setName('update-dns-view');
        $this->em->persist($view);
        $this->em->flush();

        $crawler = $this->client->request('GET', "/dns-views/{$view->getId()}/edit");
        $this->client->submit($crawler->filter('form')->form(), [
            'dns_view[name]' => 'updated-dns-view',
        ]);
        $this->assertResponseRedirects();
    }

    public function testDelete(): void
    {
        $view = (new DnsView())->setName('delete-dns-view');
        $this->em->persist($view);
        $this->em->flush();

        $id      = $view->getId();
        $crawler = $this->client->request('GET', '/dns-views');
        $this->client->submit(
            $crawler->filter('form[action="/dns-views/' . $id . '/delete"]')->form()
        );
        $this->assertResponseRedirects();
    }
}
