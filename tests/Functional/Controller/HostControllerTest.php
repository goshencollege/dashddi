<?php

namespace App\Tests\Functional\Controller;

use App\Entity\ApiToken;
use App\Entity\Host;
use App\Entity\NetworkInterface;
use App\Entity\Subnet;
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

    public function testSoftDeletedHostVisibleByDefault(): void
    {
        $host = (new Host())->setName('soft-deleted-host');
        $host->softDelete();
        $this->em->persist($host);
        $this->em->flush();

        $this->client->request('GET', '/hosts');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('soft-deleted-host', $this->client->getResponse()->getContent());
    }

    public function testDeletedFalseFilterHidesSoftDeletedHost(): void
    {
        $host = (new Host())->setName('soft-deleted-host');
        $host->softDelete();
        $this->em->persist($host);
        $this->em->flush();

        $this->client->request('GET', '/hosts?' . http_build_query(['q' => 'deleted:!1']));
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('soft-deleted-host', $this->client->getResponse()->getContent());
    }

    // -------------------------------------------------------------------------
    // Host token web routes (HostTokenController)
    // -------------------------------------------------------------------------

    public function testGenerateTokenViaWebCreatesToken(): void
    {
        $host = (new Host())->setName('web-token-host');
        $this->em->persist($host);
        $this->em->flush();

        $id = $host->getId();
        $this->em->clear(); // evict so controller lazy-loads apiToken fresh from DB
        $crawler = $this->client->request('GET', "/hosts/{$id}");
        $this->client->submit(
            $crawler->filter('form[action="/hosts/' . $id . '/token/generate"]')->first()->form()
        );
        $this->assertResponseRedirects('/hosts/' . $id);

        $this->em->clear();
        $found = $this->em->find(Host::class, $id);
        $this->assertNotNull($found->getApiToken());
    }

    public function testRegenerateTokenViaWebReplacesExistingToken(): void
    {
        $host = (new Host())->setName('web-regen-host');
        $this->em->persist($host);
        $existing = new ApiToken();
        $existing->setName('old token')->setOwnerIdentifier('x')->setTokenHash('oldhash')
            ->setHost($host)->setAllowedRoutes([])->setAllowedCidrs([]);
        $this->em->persist($existing);
        $this->em->flush();
        $existingId = $existing->getId();

        $id = $host->getId();
        $this->em->clear(); // evict so the existing token is visible via lazy-load
        $crawler = $this->client->request('GET', "/hosts/{$id}");
        $this->client->submit(
            $crawler->filter('form[action="/hosts/' . $id . '/token/generate"]')->first()->form()
        );
        $this->assertResponseRedirects('/hosts/' . $id);

        $this->em->clear();
        $this->assertNull($this->em->find(ApiToken::class, $existingId));
        $found = $this->em->find(Host::class, $id);
        $this->assertNotNull($found->getApiToken());
        $this->assertNotSame($existingId, $found->getApiToken()->getId());
    }

    public function testRevokeTokenViaWebDeletesToken(): void
    {
        $host = (new Host())->setName('web-revoke-host');
        $this->em->persist($host);
        $token = new ApiToken();
        $token->setName('revoke-me')->setOwnerIdentifier('x')->setTokenHash('hash')
            ->setHost($host)->setAllowedRoutes([])->setAllowedCidrs([]);
        $this->em->persist($token);
        $this->em->flush();

        $id = $host->getId();
        $this->em->clear(); // evict so the token shows up in the rendered page
        $crawler = $this->client->request('GET', "/hosts/{$id}");
        $this->client->submit(
            $crawler->filter('form[action="/hosts/' . $id . '/token/revoke"]')->form()
        );
        $this->assertResponseRedirects('/hosts/' . $id);

        $this->em->clear();
        $found = $this->em->find(Host::class, $id);
        $this->assertNull($found->getApiToken());
    }

    // -------------------------------------------------------------------------
    // DHCP subnet mismatch filter
    // -------------------------------------------------------------------------

    private function makeHostWithInterface(string $hostName, Subnet $subnet, ?string $lastDhcpIp): Host
    {
        $host  = (new Host())->setName($hostName);
        $this->em->persist($host);
        $iface = (new NetworkInterface())
            ->setHost($host)
            ->setMacAddress('aa:bb:cc:dd:ee:01')
            ->setSubnet($subnet)
            ->setLastDhcpIp($lastDhcpIp);
        $this->em->persist($iface);
        $this->em->flush();
        return $host;
    }

    public function testDhcpMismatchFilterReturnsHostWhoseDhcpIpIsOutsideAssignedSubnet(): void
    {
        $subnet = (new Subnet())->setName('test-subnet')->setIpv4Cidr('10.0.0.0/24');
        $this->em->persist($subnet);
        $this->makeHostWithInterface('mismatch-host', $subnet, '10.1.0.5');

        $this->client->request('GET', '/hosts?' . http_build_query(['q' => 'dhcp_mismatch:1']));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('mismatch-host', $this->client->getResponse()->getContent());
    }

    public function testDhcpMismatchFilterExcludesHostWhoseDhcpIpIsInsideAssignedSubnet(): void
    {
        $subnet = (new Subnet())->setName('test-subnet')->setIpv4Cidr('10.0.0.0/24');
        $this->em->persist($subnet);
        $this->makeHostWithInterface('matched-host', $subnet, '10.0.0.50');

        $this->client->request('GET', '/hosts?' . http_build_query(['q' => 'dhcp_mismatch:1']));
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('matched-host', $this->client->getResponse()->getContent());
    }

    public function testDhcpMismatchFilterExcludesHostWithNoDhcpHistory(): void
    {
        $subnet = (new Subnet())->setName('test-subnet')->setIpv4Cidr('10.0.0.0/24');
        $this->em->persist($subnet);
        $this->makeHostWithInterface('no-dhcp-host', $subnet, null);

        $this->client->request('GET', '/hosts?' . http_build_query(['q' => 'dhcp_mismatch:1']));
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('no-dhcp-host', $this->client->getResponse()->getContent());
    }
}
