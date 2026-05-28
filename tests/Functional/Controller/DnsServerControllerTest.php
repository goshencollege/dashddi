<?php

namespace App\Tests\Functional\Controller;

use App\Entity\DnsServer;
use App\Entity\DnsView;
use App\Tests\Functional\AppWebTestCase;

class DnsServerControllerTest extends AppWebTestCase
{
    private function makeServer(string $name): DnsServer
    {
        $view   = (new DnsView())->setName("view-for-$name");
        $server = (new DnsServer())
            ->setName($name)
            ->setHostname('ns.functional.example.com')
            ->setSshUser('root')
            ->setRemoteZonePath('/etc/bind/zones')
            ->setServerType('primary')
            ->addView($view);
        $this->em->persist($view);
        $this->em->persist($server);
        $this->em->flush();
        return $server;
    }

    public function testServersPageLoads(): void
    {
        $this->client->request('GET', '/servers');
        $this->assertResponseIsSuccessful();
    }

    public function testNewFormLoads(): void
    {
        $this->client->request('GET', '/dns-servers/new');
        $this->assertResponseIsSuccessful();
    }

    public function testCreate(): void
    {
        $crawler = $this->client->request('GET', '/dns-servers/new');
        $this->client->submit($crawler->filter('form')->form(), [
            'dns_server[name]'           => 'New Functional DNS Server',
            'dns_server[hostname]'       => 'ns2.functional.example.com',
            'dns_server[sshUser]'        => 'root',
            'dns_server[remoteZonePath]' => '/etc/bind/zones',
            'dns_server[serverType]'     => 'primary',
        ]);
        $this->assertResponseRedirects();
    }

    public function testEditFormLoads(): void
    {
        $server = $this->makeServer('Edit DNS Server');

        $this->client->request('GET', "/dns-servers/{$server->getId()}/edit");
        $this->assertResponseIsSuccessful();
    }

    public function testUpdate(): void
    {
        $server  = $this->makeServer('Update DNS Server');
        $crawler = $this->client->request('GET', "/dns-servers/{$server->getId()}/edit");
        $this->client->submit($crawler->filter('form')->form(), [
            'dns_server[name]' => 'Updated DNS Server',
        ]);
        $this->assertResponseRedirects();
    }

    public function testDelete(): void
    {
        $server  = $this->makeServer('Delete DNS Server');
        $id      = $server->getId();
        $crawler = $this->client->request('GET', '/servers');
        $this->client->submit(
            $crawler->filter('form[action="/dns-servers/' . $id . '/delete"]')->form()
        );
        $this->assertResponseRedirects();
    }
}
