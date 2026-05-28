<?php

namespace App\Tests\Functional\Controller;

use App\Entity\DnsView;
use App\Entity\Domain;
use App\Tests\Functional\AppWebTestCase;

class DomainControllerTest extends AppWebTestCase
{
    private function makeView(string $name = 'test-view'): DnsView
    {
        $view = (new DnsView())->setName($name);
        $this->em->persist($view);
        $this->em->flush();
        return $view;
    }

    public function testIndexLoads(): void
    {
        $this->client->request('GET', '/domains');
        $this->assertResponseIsSuccessful();
    }

    public function testNewFormLoads(): void
    {
        $this->client->request('GET', '/domains/new');
        $this->assertResponseIsSuccessful();
    }

    public function testCreate(): void
    {
        $crawler = $this->client->request('GET', '/domains/new');
        $this->client->submit($crawler->filter('form')->form(), [
            'domain[name]' => 'functional-create.example.com',
        ]);
        $this->assertResponseRedirects();
    }

    public function testShowLoads(): void
    {
        $view   = $this->makeView('domain-show-view');
        $domain = (new Domain())->setName('functional-show.example.com')->addView($view);
        $this->em->persist($domain);
        $this->em->flush();

        $this->client->request('GET', "/domains/{$domain->getId()}");
        $this->assertResponseIsSuccessful();
    }

    public function testEditFormLoads(): void
    {
        $view   = $this->makeView('domain-edit-view');
        $domain = (new Domain())->setName('functional-edit.example.com')->addView($view);
        $this->em->persist($domain);
        $this->em->flush();

        $this->client->request('GET', "/domains/{$domain->getId()}/edit");
        $this->assertResponseIsSuccessful();
    }

    public function testUpdate(): void
    {
        $view   = $this->makeView('domain-update-view');
        $domain = (new Domain())->setName('functional-update.example.com')->addView($view);
        $this->em->persist($domain);
        $this->em->flush();

        $crawler = $this->client->request('GET', "/domains/{$domain->getId()}/edit");
        $this->client->submit($crawler->filter('form')->form(), [
            'domain[name]' => 'functional-updated.example.com',
        ]);
        $this->assertResponseRedirects();
    }

    public function testDelete(): void
    {
        $view   = $this->makeView('domain-delete-view');
        $domain = (new Domain())->setName('functional-delete.example.com')->addView($view);
        $this->em->persist($domain);
        $this->em->flush();

        $id      = $domain->getId();
        $crawler = $this->client->request('GET', '/domains');
        $this->client->submit(
            $crawler->filter('form[action="/domains/' . $id . '/delete"]')->form()
        );
        $this->assertResponseRedirects();
    }
}
