<?php

namespace App\Tests\Functional\Controller;

use App\Controller\DhcpLeaseController;
use App\Entity\DhcpServer;
use App\Entity\UserPreference;
use App\Repository\DhcpServerRepository;
use App\Tests\Functional\AppWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class DhcpLeaseControllerTest extends AppWebTestCase
{
    public function testIndexLoads(): void
    {
        $this->client->request('GET', '/dhcp/leases');
        $this->assertResponseIsSuccessful();
    }

    public function testSearchIsSavedToUserPreference(): void
    {
        $this->client->request('GET', '/dhcp/leases?mac=aa%3Abb%3Acc');
        $this->assertResponseIsSuccessful();

        $pref = $this->em->getRepository(UserPreference::class)->findByIdentifier('test@example.com');
        $this->assertSame(['mac' => 'aa:bb:cc'], $pref->getDhcpLeaseSearch());
    }

    public function testPlainVisitRedirectsToRestoreSavedSearch(): void
    {
        $this->client->request('GET', '/dhcp/leases?mac=aa%3Abb%3Acc');

        $this->client->request('GET', '/dhcp/leases');
        $this->assertResponseRedirects();
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('value="aa:bb:cc"', $this->client->getResponse()->getContent());
    }

    public function testResetClearsSavedSearch(): void
    {
        $this->client->request('GET', '/dhcp/leases?mac=aa%3Abb%3Acc');
        $this->client->request('GET', '/dhcp/leases?reset=1');
        $this->assertResponseRedirects('/dhcp/leases');

        $pref = $this->em->getRepository(UserPreference::class)->findByIdentifier('test@example.com');
        $this->assertNull($pref->getDhcpLeaseSearch());

        $this->client->request('GET', '/dhcp/leases');
        $this->assertResponseIsSuccessful();
    }

    public function testReceiveLeaseAssignsServerByLiteralHostnameMatch(): void
    {
        // KernelBrowser always reports REMOTE_ADDR as 127.0.0.1, so a server
        // configured with that literal address should be matched.
        $server = new DhcpServer();
        $server->setName('kea-1')->setHostname('127.0.0.1');
        $this->em->persist($server);
        $this->em->flush();

        $response = $this->apiRequest('POST', '/api/dhcp/lease', [
            'ip-address' => '192.0.2.10',
            'hw-address' => 'aa:bb:cc:dd:ee:ff',
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->em->clear();
        $lease = $this->em->getRepository(\App\Entity\DhcpLease::class)->find($response['id']);
        $this->assertNotNull($lease->getDhcpServer());
        $this->assertSame($server->getId(), $lease->getDhcpServer()->getId());
    }

    #[DataProvider('identifyServerProvider')]
    public function testIdentifyServerMatchesAcrossAddressFamilyRepresentations(string $configuredHostname, string $incomingClientIp): void
    {
        $server = new DhcpServer();
        $server->setName('kea-1')->setHostname($configuredHostname);
        $this->em->persist($server);
        $this->em->flush();

        /** @var DhcpServerRepository $repo */
        $repo = $this->em->getRepository(DhcpServer::class);

        $controller = new DhcpLeaseController();
        $method = new \ReflectionMethod($controller, 'identifyServer');
        $method->setAccessible(true);
        $matched = $method->invoke($controller, $incomingClientIp, $repo);

        $this->assertNotNull($matched);
        $this->assertSame($server->getId(), $matched->getId());
    }

    public static function identifyServerProvider(): array
    {
        return [
            'IPv4-mapped IPv6 client matches plain IPv4 hostname' => ['10.0.0.5', '::ffff:10.0.0.5'],
            'expanded IPv6 client matches compressed IPv6 hostname' => ['2001:db8::1', '2001:0db8:0000:0000:0000:0000:0000:0001'],
            'uppercase IPv6 client matches lowercase IPv6 hostname' => ['2001:db8::abcd', '2001:0DB8:0000:0000:0000:0000:0000:ABCD'],
        ];
    }
}
