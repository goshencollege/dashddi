<?php

namespace App\Tests\Functional\Controller;

use App\Entity\UserPreference;
use App\Tests\Functional\AppWebTestCase;

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
}
