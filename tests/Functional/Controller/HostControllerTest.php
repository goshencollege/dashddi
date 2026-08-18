<?php

namespace App\Tests\Functional\Controller;

use App\Entity\ApiToken;
use App\Entity\AppSetting;
use App\Entity\ArubaSwitch;
use App\Entity\DnsView;
use App\Entity\Domain;
use App\Entity\DomainRecord;
use App\Entity\Host;
use App\Entity\IpAddress;
use App\Entity\Ipv6Address;
use App\Entity\NetworkInterface;
use App\Entity\Subnet;
use App\Entity\UserPreference;
use App\Enum\RecordType;
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

    public function testShowRendersSectionsExpandedByDefault(): void
    {
        $host = (new Host())->setName('sections-expanded-host');
        $this->em->persist($host);
        $this->em->flush();

        $this->client->request('GET', "/hosts/{$host->getId()}");
        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('class="collapse show" id="section-interfaces"', $content);
        $this->assertStringContainsString('aria-expanded="true" aria-controls="section-interfaces"', $content);
    }

    public function testShowRendersCollapsedSectionFromUserPreference(): void
    {
        $pref = new UserPreference('test@example.com');
        $pref->setHostCollapsedSections(['interfaces']);
        $this->em->persist($pref);

        $host = (new Host())->setName('sections-collapsed-host');
        $this->em->persist($host);
        $this->em->flush();

        $this->client->request('GET', "/hosts/{$host->getId()}");
        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('class="collapse" id="section-interfaces"', $content);
        $this->assertStringContainsString('aria-expanded="false" aria-controls="section-interfaces"', $content);
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

    public function testEditFormPrefillsDuidInNetworkctlFormat(): void
    {
        $host = (new Host())->setName('duid-prefill-host');
        $host->setDuid('00020000ab11cc5702f3da97b768'); // stored as 00:02:00:00:ab:11:cc:57:02:f3:da:97:b7:68
        $this->em->persist($host);
        $this->em->flush();

        $crawler = $this->client->request('GET', "/hosts/{$host->getId()}/edit");
        $this->assertSame('DUID-EN/Vendor:0000ab11cc5702f3da97b768', $crawler->filter('form')->form()->get('host[duid]')->getValue());
    }

    public function testUpdateRoundTripsDuidPastedInNetworkctlFormat(): void
    {
        $host = (new Host())->setName('duid-roundtrip-host');
        $this->em->persist($host);
        $this->em->flush();

        $crawler = $this->client->request('GET', "/hosts/{$host->getId()}/edit");
        $this->client->submit($crawler->filter('form')->form(), [
            'host[duid]' => 'DUID-EN/Vendor:0000ab11cc5702f3da97b768',
        ]);
        $this->assertResponseRedirects();

        $this->em->clear();
        $updated = $this->em->getRepository(Host::class)->find($host->getId());
        $this->assertSame('00:02:00:00:ab:11:cc:57:02:f3:da:97:b7:68', $updated->getDuid());
    }

    public function testPlainSearchMatchesHostByDuid(): void
    {
        $host = (new Host())->setName('duid-search-host');
        $host->setDuid('00020000ab11cc5702f3da97b768');
        $this->em->persist($host);

        $other = (new Host())->setName('duid-search-other');
        $this->em->persist($other);
        $this->em->flush();

        $this->client->request('GET', '/hosts?' . http_build_query(['q' => 'cc:57:02']));
        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('duid-search-host', $content);
        $this->assertStringNotContainsString('duid-search-other', $content);
    }

    public function testStructuredSearchMatchesHostByDuid(): void
    {
        $host = (new Host())->setName('duid-structured-host');
        $host->setDuid('00020000ab11cc5702f3da97b768');
        $this->em->persist($host);

        $other = (new Host())->setName('duid-structured-other');
        $this->em->persist($other);
        $this->em->flush();

        $this->client->request('GET', '/hosts?' . http_build_query(['q' => 'duid:cc5702']));
        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('duid-structured-host', $content);
        $this->assertStringNotContainsString('duid-structured-other', $content);
    }

    public function testPlainSearchMatchesHostByDuidTypeLabel(): void
    {
        $enVendor = (new Host())->setName('duid-label-en-vendor');
        $enVendor->setDuid('00020000ab11cc5702f3da97b768'); // DUID-EN/Vendor
        $this->em->persist($enVendor);

        $llt = (new Host())->setName('duid-label-llt');
        $llt->setDuid('00010000ab11cc5702f3da97b768'); // DUID-LLT
        $this->em->persist($llt);
        $this->em->flush();

        $this->client->request('GET', '/hosts?' . http_build_query(['q' => 'DUID-EN/Vendor']));
        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('duid-label-en-vendor', $content);
        $this->assertStringNotContainsString('duid-label-llt', $content);
    }

    public function testStructuredSearchMatchesHostByDuidTypeLabel(): void
    {
        $enVendor = (new Host())->setName('duid-structured-label-en-vendor');
        $enVendor->setDuid('00020000ab11cc5702f3da97b768'); // DUID-EN/Vendor
        $this->em->persist($enVendor);

        $llt = (new Host())->setName('duid-structured-label-llt');
        $llt->setDuid('00010000ab11cc5702f3da97b768'); // DUID-LLT
        $this->em->persist($llt);
        $this->em->flush();

        $this->client->request('GET', '/hosts?' . http_build_query(['q' => 'duid:DUID-EN/Vendor']));
        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('duid-structured-label-en-vendor', $content);
        $this->assertStringNotContainsString('duid-structured-label-llt', $content);
    }

    public function testPlainSearchIpLikeQueryDoesNotFalsePositiveOnDuidHexDigits(): void
    {
        $host = (new Host())->setName('duid-ip-guard-host');
        $host->setDuid('00100200ab11cc5702f3da97b768'); // hex digits happen to contain "10.02.00"-like substrings
        $this->em->persist($host);
        $this->em->flush();

        $this->client->request('GET', '/hosts?' . http_build_query(['q' => '10.02.00']));
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('duid-ip-guard-host', $this->client->getResponse()->getContent());
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

    // -------------------------------------------------------------------------
    // Bulk create interface DNS records
    // -------------------------------------------------------------------------

    private function makeInterfaceWithIps(
        Host $host,
        Subnet $subnet,
        string $mac,
        ?string $name,
        ?string $ipv4 = null,
        ?string $ipv6 = null,
    ): NetworkInterface {
        $iface = (new NetworkInterface())
            ->setHost($host)
            ->setSubnet($subnet)
            ->setMacAddress($mac)
            ->setName($name);
        if ($ipv4 !== null) {
            $ip = (new IpAddress())->setAddress($ipv4)->setSubnet($subnet);
            $this->em->persist($ip);
            $iface->setIpAddress($ip);
        }
        if ($ipv6 !== null) {
            $ip6 = (new Ipv6Address())->setAddress($ipv6)->setSubnet($subnet);
            $this->em->persist($ip6);
            $iface->setIpv6Address($ip6);
        }
        $this->em->persist($iface);
        return $iface;
    }

    public function testCreateInterfaceDnsRecordsCreatesAAndAaaaForNamedInterfaces(): void
    {
        $subnet = (new Subnet())->setName('bulk-dns-subnet')->setIpv4Cidr('10.5.0.0/24');
        $this->em->persist($subnet);
        $domain = (new Domain())->setName('bulk-dns.test');
        $this->em->persist($domain);
        $host = (new Host())->setName('switch1');
        $this->em->persist($host);

        $dualStack = $this->makeInterfaceWithIps($host, $subnet, 'aa:bb:cc:dd:ee:01', 'eth0', '10.5.0.10', '2001:db8::10');
        $ipv4Only  = $this->makeInterfaceWithIps($host, $subnet, 'aa:bb:cc:dd:ee:02', 'eth1', '10.5.0.11');
        $unnamed   = $this->makeInterfaceWithIps($host, $subnet, 'aa:bb:cc:dd:ee:03', null, '10.5.0.12');
        $invalid   = $this->makeInterfaceWithIps($host, $subnet, 'aa:bb:cc:dd:ee:04', 'Gi1/0/24', '10.5.0.13');
        $deleted   = $this->makeInterfaceWithIps($host, $subnet, 'aa:bb:cc:dd:ee:05', 'eth2', '10.5.0.14');
        $deleted->softDelete();
        $this->em->flush();

        $hostId   = $host->getId();
        $domainId = $domain->getId();
        $ids      = [
            'dualStack' => $dualStack->getId(),
            'ipv4Only'  => $ipv4Only->getId(),
            'unnamed'   => $unnamed->getId(),
            'invalid'   => $invalid->getId(),
            'deleted'   => $deleted->getId(),
        ];
        $this->em->clear(); // evict identity map so Host::interfaces lazy-loads fresh from the DB

        $crawler = $this->client->request('GET', "/hosts/{$hostId}");
        $this->client->submit(
            $crawler->filter('form[action="/hosts/' . $hostId . '/create-interface-dns-records"]')->form(),
            ['domain_id' => $domainId],
        );
        $this->assertResponseRedirects('/hosts/' . $hostId);

        $this->em->clear();
        $recordRepo = $this->em->getRepository(DomainRecord::class);

        $dualStackRecords = $recordRepo->findBy(['networkInterface' => $ids['dualStack']]);
        $this->assertCount(2, $dualStackRecords);
        $types = array_map(fn($r) => $r->getType(), $dualStackRecords);
        $this->assertContains(RecordType::A, $types);
        $this->assertContains(RecordType::AAAA, $types);
        foreach ($dualStackRecords as $r) {
            $this->assertSame('eth0.switch1', $r->getHostname());
            $this->assertTrue($r->isCanonical(), 'First record of each type for an interface should be canonical');
        }

        $ipv4OnlyRecords = $recordRepo->findBy(['networkInterface' => $ids['ipv4Only']]);
        $this->assertCount(1, $ipv4OnlyRecords);
        $this->assertSame(RecordType::A, $ipv4OnlyRecords[0]->getType());
        $this->assertSame('eth1.switch1', $ipv4OnlyRecords[0]->getHostname());

        $this->assertCount(0, $recordRepo->findBy(['networkInterface' => $ids['unnamed']]));
        $this->assertCount(0, $recordRepo->findBy(['networkInterface' => $ids['invalid']]));
        $this->assertCount(0, $recordRepo->findBy(['networkInterface' => $ids['deleted']]));
    }

    public function testCreateInterfaceDnsRecordsSkipsHostWithInvalidName(): void
    {
        $subnet = (new Subnet())->setName('bad-host-subnet')->setIpv4Cidr('10.6.0.0/24');
        $this->em->persist($subnet);
        $domain = (new Domain())->setName('bad-host-dns.test');
        $this->em->persist($domain);
        $host = (new Host())->setName('switch one'); // space is not a valid DNS label character
        $this->em->persist($host);
        $iface = $this->makeInterfaceWithIps($host, $subnet, 'bb:cc:dd:ee:ff:01', 'eth0', '10.6.0.10');
        $this->em->flush();

        $hostId = $host->getId();
        $ifaceId = $iface->getId();
        $domainId = $domain->getId();
        $this->em->clear();

        $crawler = $this->client->request('GET', "/hosts/{$hostId}");
        $this->client->submit(
            $crawler->filter('form[action="/hosts/' . $hostId . '/create-interface-dns-records"]')->form(),
            ['domain_id' => $domainId],
        );
        $this->assertResponseRedirects('/hosts/' . $hostId);

        $this->em->clear();
        $recordRepo = $this->em->getRepository(DomainRecord::class);
        $this->assertCount(0, $recordRepo->findBy(['networkInterface' => $ifaceId]));
    }

    public function testCreateInterfaceDnsRecordsIsIdempotentOnRepeatSubmit(): void
    {
        $subnet = (new Subnet())->setName('idem-dns-subnet')->setIpv4Cidr('10.7.0.0/24');
        $this->em->persist($subnet);
        $domain = (new Domain())->setName('idem-dns.test');
        $this->em->persist($domain);
        $host = (new Host())->setName('switch2');
        $this->em->persist($host);
        $iface = $this->makeInterfaceWithIps($host, $subnet, 'cc:dd:ee:ff:00:01', 'eth0', '10.7.0.10', '2001:db8::20');
        $this->em->flush();

        $hostId   = $host->getId();
        $ifaceId  = $iface->getId();
        $domainId = $domain->getId();
        $this->em->clear();

        $formAction = '/hosts/' . $hostId . '/create-interface-dns-records';

        $crawler = $this->client->request('GET', "/hosts/{$hostId}");
        $this->client->submit(
            $crawler->filter('form[action="' . $formAction . '"]')->form(),
            ['domain_id' => $domainId],
        );
        $this->assertResponseRedirects('/hosts/' . $hostId);

        $this->em->clear();
        $crawler = $this->client->request('GET', "/hosts/{$hostId}");
        $this->client->submit(
            $crawler->filter('form[action="' . $formAction . '"]')->form(),
            ['domain_id' => $domainId],
        );
        $this->assertResponseRedirects('/hosts/' . $hostId);

        $this->em->clear();
        $recordRepo = $this->em->getRepository(DomainRecord::class);
        $this->assertCount(2, $recordRepo->findBy(['networkInterface' => $ifaceId]), 'Repeat submission must not create duplicate records');
    }

    public function testCreateInterfaceDnsRecordsRejectsInvalidCsrfToken(): void
    {
        $subnet = (new Subnet())->setName('csrf-dns-subnet')->setIpv4Cidr('10.8.0.0/24');
        $this->em->persist($subnet);
        $domain = (new Domain())->setName('csrf-dns.test');
        $this->em->persist($domain);
        $host = (new Host())->setName('switch3');
        $this->em->persist($host);
        $iface = $this->makeInterfaceWithIps($host, $subnet, 'dd:ee:ff:00:11:22', 'eth0', '10.8.0.10');
        $this->em->flush();

        $hostId   = $host->getId();
        $ifaceId  = $iface->getId();
        $domainId = $domain->getId();
        $this->em->clear(); // evict identity map so Host::interfaces lazy-loads fresh from the DB

        $crawler = $this->client->request('GET', "/hosts/{$hostId}");
        $form = $crawler->filter('form[action="/hosts/' . $hostId . '/create-interface-dns-records"]')->form();
        $form['domain_id'] = $domainId;
        $form['_token']    = 'not-a-valid-token';
        $this->client->submit($form);
        $this->assertResponseRedirects('/hosts/' . $hostId);

        $this->em->clear();
        $recordRepo = $this->em->getRepository(DomainRecord::class);
        $this->assertCount(0, $recordRepo->findBy(['networkInterface' => $ifaceId]));
    }

    public function testCreateInterfaceDnsRecordsAutoAssignsAvailableViews(): void
    {
        $view = (new DnsView())->setName('bulk-dns-view');
        $this->em->persist($view);
        $subnet = (new Subnet())->setName('view-dns-subnet')->setIpv4Cidr('10.9.0.0/24');
        $subnet->addView($view);
        $this->em->persist($subnet);
        $domain = (new Domain())->setName('view-dns.test');
        $domain->addView($view);
        $this->em->persist($domain);
        $host = (new Host())->setName('switch4');
        $this->em->persist($host);
        $iface = $this->makeInterfaceWithIps($host, $subnet, 'ee:ff:00:11:22:33', 'eth0', '10.9.0.10');
        $this->em->flush();

        $hostId   = $host->getId();
        $ifaceId  = $iface->getId();
        $domainId = $domain->getId();
        $this->em->clear();

        $crawler = $this->client->request('GET', "/hosts/{$hostId}");
        $this->client->submit(
            $crawler->filter('form[action="/hosts/' . $hostId . '/create-interface-dns-records"]')->form(),
            ['domain_id' => $domainId],
        );
        $this->assertResponseRedirects('/hosts/' . $hostId);

        $this->em->clear();
        $record = $this->em->getRepository(DomainRecord::class)->findOneBy(['networkInterface' => $ifaceId]);
        $this->assertNotNull($record);
        $viewNames = $record->getViews()->map(fn($v) => $v->getName())->toArray();
        $this->assertContains('bulk-dns-view', $viewNames);
    }

    // -------------------------------------------------------------------------
    // Switch Ports card
    // -------------------------------------------------------------------------

    public function testShowRendersSwitchPortsCardWhenAnotherInterfacePointsAtHostIp(): void
    {
        $subnet = (new Subnet())->setName('switch-ports-subnet')->setIpv4Cidr('10.20.0.0/24');
        $this->em->persist($subnet);

        $switchHost = (new Host())->setName('switch-host');
        $this->em->persist($switchHost);
        $this->makeInterfaceWithIps($switchHost, $subnet, 'aa:aa:aa:aa:aa:01', 'mgmt', '10.20.0.1');

        $clientHost = (new Host())->setName('client-host');
        $this->em->persist($clientHost);
        $clientIface = $this->makeInterfaceWithIps($clientHost, $subnet, 'bb:bb:bb:bb:bb:02', 'eth0', '10.20.0.50');
        $clientIface->setSwitchIp('10.20.0.1')->setSwitchPort('1/1/5')->setLastAuthAt(new \DateTimeImmutable());
        $this->em->flush();

        $switchHostId = $switchHost->getId();
        $clientIfaceId = $clientIface->getId();
        $this->em->clear();

        $this->client->request('GET', "/hosts/{$switchHostId}");
        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Switch Ports', $content);
        $this->assertStringContainsString('1/1/5', $content);
        $this->assertStringContainsString('client-host', $content);
        $this->assertStringContainsString("/interfaces/{$clientIfaceId}", $content);
        $this->assertStringNotContainsString('port-status-btn', $content);
        $this->assertStringNotContainsString('switch-port-checkbox', $content);
        $this->assertStringNotContainsString('bulk-status-btn', $content);
    }

    public function testShowGroupsMultipleDevicesOnTheSameSwitchPortIntoOneRow(): void
    {
        $subnet = (new Subnet())->setName('switch-shared-port-subnet')->setIpv4Cidr('10.23.0.0/24');
        $this->em->persist($subnet);

        $switchHost = (new Host())->setName('switch-host-shared');
        $this->em->persist($switchHost);
        $this->makeInterfaceWithIps($switchHost, $subnet, 'aa:aa:aa:aa:aa:07', 'mgmt', '10.23.0.1');

        $phoneHost = (new Host())->setName('phone-host');
        $this->em->persist($phoneHost);
        $phoneIface = $this->makeInterfaceWithIps($phoneHost, $subnet, 'bb:bb:bb:bb:bb:08', 'phone', '10.23.0.60');
        $phoneIface->setSwitchIp('10.23.0.1')->setSwitchPort('1/1/8')->setLastAuthAt(new \DateTimeImmutable('-1 hour'));

        $pcHost = (new Host())->setName('pc-host');
        $this->em->persist($pcHost);
        $pcIface = $this->makeInterfaceWithIps($pcHost, $subnet, 'bb:bb:bb:bb:bb:09', 'pc', '10.23.0.61');
        $pcIface->setSwitchIp('10.23.0.1')->setSwitchPort('1/1/8')->setLastAuthAt(new \DateTimeImmutable());
        $this->em->flush();

        $switchHostId = $switchHost->getId();
        $pcIfaceId    = $pcIface->getId();
        $phoneIfaceId = $phoneIface->getId();
        $this->em->clear();

        $this->client->request('GET', "/hosts/{$switchHostId}");
        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();

        $this->assertStringContainsString('Switch Ports (1)', $content);
        $this->assertSame(1, substr_count($content, '1/1/8'));
        $this->assertStringContainsString('phone-host', $content);
        $this->assertStringContainsString('pc-host', $content);
        $this->assertStringContainsString("/interfaces/{$pcIfaceId}", $content);
        $this->assertStringContainsString("/interfaces/{$phoneIfaceId}", $content);
    }

    public function testShowRendersSwitchPortActionButtonsWhenArubaSwitchConfigured(): void
    {
        $arubaSwitch = (new ArubaSwitch())->setUsername('admin')->setPassword('secret');
        $this->em->persist($arubaSwitch);

        $subnet = (new Subnet())->setName('switch-actions-subnet')->setIpv4Cidr('10.21.0.0/24');
        $this->em->persist($subnet);

        $switchHost = (new Host())->setName('switch-host-2');
        $this->em->persist($switchHost);
        $this->makeInterfaceWithIps($switchHost, $subnet, 'aa:aa:aa:aa:aa:03', 'mgmt', '10.21.0.1');

        $clientHost = (new Host())->setName('client-host-2');
        $this->em->persist($clientHost);
        $clientIface = $this->makeInterfaceWithIps($clientHost, $subnet, 'bb:bb:bb:bb:bb:04', 'eth0', '10.21.0.50');
        $clientIface->setSwitchIp('10.21.0.1')->setSwitchPort('1/1/6')->setLastAuthAt(new \DateTimeImmutable());
        $this->em->flush();

        $switchHostId = $switchHost->getId();
        $this->em->clear();

        $this->client->request('GET', "/hosts/{$switchHostId}");
        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('port-status-btn', $content);
        $this->assertStringContainsString('port-reauth-btn', $content);
        $this->assertStringContainsString('port-bounce-btn', $content);
        $this->assertStringContainsString('port-poe-bounce-btn', $content);
        $this->assertStringContainsString('bb:bb:bb:bb:bb:04', $content);
        $this->assertStringContainsString('switch-ports-select-all', $content);
        $this->assertStringContainsString('switch-port-checkbox', $content);
        $this->assertStringContainsString('bulk-status-btn', $content);
        $this->assertStringContainsString('bulk-reauth-btn', $content);
        $this->assertStringContainsString('bulk-bounce-btn', $content);
        $this->assertStringContainsString('bulk-poe-bounce-btn', $content);
    }

    public function testShowHidesSwitchPortEntryOlderThanSwitchInfoMaxAge(): void
    {
        $setting = $this->em->getRepository(AppSetting::class)->find(1) ?? new AppSetting();
        $setting->setSwitchInfoMaxAgeDays(1);
        $this->em->persist($setting);

        $subnet = (new Subnet())->setName('switch-stale-subnet')->setIpv4Cidr('10.22.0.0/24');
        $this->em->persist($subnet);

        $switchHost = (new Host())->setName('switch-host-3');
        $this->em->persist($switchHost);
        $this->makeInterfaceWithIps($switchHost, $subnet, 'aa:aa:aa:aa:aa:05', 'mgmt', '10.22.0.1');

        $clientHost = (new Host())->setName('stale-client-host');
        $this->em->persist($clientHost);
        $clientIface = $this->makeInterfaceWithIps($clientHost, $subnet, 'bb:bb:bb:bb:bb:06', 'eth0', '10.22.0.50');
        $clientIface->setSwitchIp('10.22.0.1')->setSwitchPort('1/1/7')->setLastAuthAt(new \DateTimeImmutable('-5 days'));
        $this->em->flush();

        $switchHostId = $switchHost->getId();
        $this->em->clear();

        $this->client->request('GET', "/hosts/{$switchHostId}");
        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('Switch Ports', $content);
        $this->assertStringNotContainsString('stale-client-host', $content);
    }

    public function testShowHidesSwitchPortsCardWhenNoInterfacesPointAtHost(): void
    {
        $host = (new Host())->setName('non-switch-host');
        $this->em->persist($host);
        $this->em->flush();

        $this->client->request('GET', "/hosts/{$host->getId()}");
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('Switch Ports', $this->client->getResponse()->getContent());
    }
}
