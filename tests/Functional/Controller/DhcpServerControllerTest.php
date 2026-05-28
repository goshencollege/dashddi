<?php

namespace App\Tests\Functional\Controller;

use App\Entity\DhcpServer;
use App\Tests\Functional\AppWebTestCase;

class DhcpServerControllerTest extends AppWebTestCase
{
    private function makeServer(string $name): DhcpServer
    {
        $server = (new DhcpServer())
            ->setName($name)
            ->setHostname('dhcp.functional.example.com')
            ->setSshUser('root')
            ->setRemotePath('/etc/kea');
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
        $this->client->request('GET', '/dhcp-servers/new');
        $this->assertResponseIsSuccessful();
    }

    public function testCreate(): void
    {
        $crawler = $this->client->request('GET', '/dhcp-servers/new');
        $this->client->submit($crawler->filter('form')->form(), [
            'dhcp_server[name]'       => 'New Functional DHCP Server',
            'dhcp_server[hostname]'   => 'dhcp2.functional.example.com',
            'dhcp_server[sshUser]'    => 'root',
            'dhcp_server[remotePath]' => '/etc/kea',
        ]);
        $this->assertResponseRedirects();
    }

    public function testEditFormLoads(): void
    {
        $server = $this->makeServer('Edit DHCP Server');

        $this->client->request('GET', "/dhcp-servers/{$server->getId()}/edit");
        $this->assertResponseIsSuccessful();
    }

    public function testUpdate(): void
    {
        $server  = $this->makeServer('Update DHCP Server');
        $crawler = $this->client->request('GET', "/dhcp-servers/{$server->getId()}/edit");
        $this->client->submit($crawler->filter('form')->form(), [
            'dhcp_server[name]' => 'Updated DHCP Server',
        ]);
        $this->assertResponseRedirects();
    }

    public function testDelete(): void
    {
        $server  = $this->makeServer('Delete DHCP Server');
        $id      = $server->getId();
        $crawler = $this->client->request('GET', '/servers');
        $this->client->submit(
            $crawler->filter('form[action="/dhcp-servers/' . $id . '/delete"]')->form()
        );
        $this->assertResponseRedirects();
    }
}
