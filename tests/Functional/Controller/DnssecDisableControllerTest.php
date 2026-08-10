<?php

namespace App\Tests\Functional\Controller;

use App\Entity\DnssecDisableRequest;
use App\Entity\DnssecPolicy;
use App\Entity\Domain;
use App\Tests\Functional\AppWebTestCase;

class DnssecDisableControllerTest extends AppWebTestCase
{
    public function testIndexLoads(): void
    {
        $this->client->request('GET', '/dnssec/disable');
        $this->assertResponseIsSuccessful();
    }

    public function testStartFormLoads(): void
    {
        $this->client->request('GET', '/dnssec/disable/start');
        $this->assertResponseIsSuccessful();
    }

    public function testStartFormPreselectsZoneFromDomainQueryParam(): void
    {
        $policy = (new DnssecPolicy())->setName('disable-preselect-policy');
        $domain = (new Domain())->setName('disable-preselect.example.com')->setDnssecPolicy($policy);
        $this->em->persist($policy);
        $this->em->persist($domain);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/dnssec/disable/start?domain=' . $domain->getId());
        $this->assertResponseIsSuccessful();
        $this->assertSame(
            'domain:' . $domain->getId(),
            $crawler->filter('select[name="dnssec_disable_start[zone]"] option[selected]')->attr('value')
        );
    }

    public function testShowLoads(): void
    {
        $policy         = (new DnssecPolicy())->setName('disable-show-policy');
        $domain         = (new Domain())->setName('disable-show.example.com')->setDnssecPolicy($policy);
        $disableRequest = (new DnssecDisableRequest())->setDomain($domain);
        $this->em->persist($policy);
        $this->em->persist($domain);
        $this->em->persist($disableRequest);
        $this->em->flush();

        $this->client->request('GET', '/dnssec/disable/' . $disableRequest->getId());
        $this->assertResponseIsSuccessful();
    }
}
