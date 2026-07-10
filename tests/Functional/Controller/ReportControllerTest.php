<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Domain;
use App\Entity\DomainRecord;
use App\Entity\Host;
use App\Entity\IpAddress;
use App\Entity\Ipv6Address;
use App\Entity\NetworkInterface;
use App\Entity\Subnet;
use App\Enum\RecordType;
use App\Service\RecommendationService;
use App\Tests\Functional\AppWebTestCase;
use Symfony\Component\DomCrawler\Crawler;

class ReportControllerTest extends AppWebTestCase
{
    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeSubnet(string $name, string $cidr): Subnet
    {
        $subnet = (new Subnet())->setName($name)->setIpv4Cidr($cidr);
        $this->em->persist($subnet);
        return $subnet;
    }

    private function makeDomain(string $name): Domain
    {
        $domain = (new Domain())->setName($name);
        $this->em->persist($domain);
        return $domain;
    }

    private function makeInterface(Host $host, Subnet $subnet, string $mac = 'aa:bb:cc:dd:ee:01'): NetworkInterface
    {
        $iface = (new NetworkInterface())
            ->setHost($host)
            ->setSubnet($subnet)
            ->setMacAddress($mac);
        $this->em->persist($iface);
        return $iface;
    }

    private function makeHost(string $name): Host
    {
        $host = (new Host())->setName($name);
        $this->em->persist($host);
        return $host;
    }

    /** GET /recommendations, initialize session, and return the crawler. */
    private function getRecommendationsPage(): Crawler
    {
        return $this->client->request('GET', '/recommendations');
    }

    /** Extract a CSRF token from the always-present hidden token container. */
    private function extractToken(Crawler $crawler, string $id): string
    {
        return $crawler->filter('#' . $id)->text();
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function testRecommendationsIndexLoads(): void
    {
        $this->client->request('GET', '/recommendations');
        $this->assertResponseIsSuccessful();
    }

    // ── link-dns ──────────────────────────────────────────────────────────────

    public function testLinkDnsLinksRecordToMatchingInterface(): void
    {
        $subnet = $this->makeSubnet('net', '10.1.0.0/24');
        $ip     = (new IpAddress())->setAddress('10.1.0.5')->setSubnet($subnet);
        $this->em->persist($ip);
        $host  = $this->makeHost('link-host');
        $iface = $this->makeInterface($host, $subnet);
        $iface->setIpAddress($ip);
        $domain = $this->makeDomain('link.test');
        $record = (new DomainRecord())
            ->setDomain($domain)
            ->setHostname('srv')
            ->setType(RecordType::A)
            ->setValue('10.1.0.5');
        $this->em->persist($record);
        $this->em->flush();

        $crawler = $this->getRecommendationsPage();
        $token   = $this->extractToken($crawler, 'csrf-link-dns');

        $this->client->request('POST', '/recommendations/apply/link-dns', [
            '_token' => $token,
            'ids'    => [$record->getId()],
        ]);
        $this->assertResponseRedirects('/recommendations');

        $this->em->clear();
        $refreshed = $this->em->find(DomainRecord::class, $record->getId());
        $this->assertNotNull($refreshed->getNetworkInterface());
        $this->assertSame('', $refreshed->getValue());
    }

    // ── convert-cname ─────────────────────────────────────────────────────────

    public function testConvertCnameReplacesWithLinkedARecord(): void
    {
        $subnet = $this->makeSubnet('net', '10.1.0.0/24');
        $ip     = (new IpAddress())->setAddress('10.1.0.10')->setSubnet($subnet);
        $this->em->persist($ip);
        $host  = $this->makeHost('cname-host');
        $iface = $this->makeInterface($host, $subnet, 'cc:dd:ee:ff:00:01');
        $iface->setIpAddress($ip);
        $domain = $this->makeDomain('convert.test');

        $aRecord = (new DomainRecord())
            ->setDomain($domain)
            ->setHostname('www')
            ->setType(RecordType::A)
            ->setNetworkInterface($iface)
            ->setValue('');
        $this->em->persist($aRecord);

        $cnameRecord = (new DomainRecord())
            ->setDomain($domain)
            ->setHostname('alias')
            ->setType(RecordType::CNAME)
            ->setValue('www');
        $this->em->persist($cnameRecord);
        $this->em->flush();

        $crawler = $this->getRecommendationsPage();
        $token   = $this->extractToken($crawler, 'csrf-convert-cname');

        $this->client->request('POST', '/recommendations/apply/convert-cname', [
            '_token' => $token,
            'ids'    => [$cnameRecord->getId()],
        ]);
        $this->assertResponseRedirects('/recommendations');

        $this->em->clear();
        $converted = $this->em->find(DomainRecord::class, $cnameRecord->getId());
        $this->assertSame(RecordType::A, $converted->getType());
        $this->assertNotNull($converted->getNetworkInterface());
        $this->assertSame('', $converted->getValue());
    }

    // ── delete-orphaned-cname ─────────────────────────────────────────────────

    public function testDeleteOrphanedCnameRemovesRecord(): void
    {
        $domain = $this->makeDomain('orphan.test');
        $record = (new DomainRecord())
            ->setDomain($domain)
            ->setHostname('stale-alias')
            ->setType(RecordType::CNAME)
            ->setValue('gone-target');
        $this->em->persist($record);
        $this->em->flush();

        $id = $record->getId();

        $crawler = $this->getRecommendationsPage();
        $token   = $this->extractToken($crawler, 'csrf-delete-orphaned-cname');

        $this->client->request('POST', '/recommendations/apply/delete-orphaned-cname', [
            '_token' => $token,
            'ids'    => [$id],
        ]);
        $this->assertResponseRedirects('/recommendations');

        $this->em->clear();
        $this->assertNull($this->em->find(DomainRecord::class, $id));
    }

    public function testDeleteOrphanedCnameSkipsNonCnameRecords(): void
    {
        $domain = $this->makeDomain('skip.test');
        $record = (new DomainRecord())
            ->setDomain($domain)
            ->setHostname('srv')
            ->setType(RecordType::A)
            ->setValue('10.0.0.1');
        $this->em->persist($record);
        $this->em->flush();

        $crawler = $this->getRecommendationsPage();
        $token   = $this->extractToken($crawler, 'csrf-delete-orphaned-cname');

        $this->client->request('POST', '/recommendations/apply/delete-orphaned-cname', [
            '_token' => $token,
            'ids'    => [$record->getId()],
        ]);
        $this->assertResponseRedirects('/recommendations');

        $this->em->clear();
        $this->assertNotNull($this->em->find(DomainRecord::class, $record->getId()));
    }

    // ── add-dual-stack ────────────────────────────────────────────────────────

    public function testAddDualStackCreatesAAAARecord(): void
    {
        $subnet = $this->makeSubnet('ds-net', '10.1.0.0/24');
        $ip     = (new IpAddress())->setAddress('10.1.0.20')->setSubnet($subnet);
        $ipv6   = (new Ipv6Address())->setAddress('2001:db8::20')->setSubnet($subnet);
        $this->em->persist($ip);
        $this->em->persist($ipv6);
        $host  = $this->makeHost('ds-host');
        $iface = $this->makeInterface($host, $subnet, 'dd:ee:ff:00:11:22');
        $iface->setIpAddress($ip)->setIpv6Address($ipv6);
        $domain = $this->makeDomain('dualstack.test');

        $aRecord = (new DomainRecord())
            ->setDomain($domain)
            ->setHostname('host')
            ->setType(RecordType::A)
            ->setNetworkInterface($iface)
            ->setValue('');
        $this->em->persist($aRecord);
        $this->em->flush();

        $crawler = $this->getRecommendationsPage();
        $token   = $this->extractToken($crawler, 'csrf-add-dual-stack');

        $this->client->request('POST', '/recommendations/apply/add-dual-stack', [
            '_token'  => $token,
            'targets' => [$aRecord->getId() . ':AAAA'],
        ]);
        $this->assertResponseRedirects('/recommendations');

        $this->em->clear();
        $records = $this->em->getRepository(DomainRecord::class)->findBy([
            'networkInterface' => $iface->getId(),
            'hostname'         => 'host',
        ]);
        $types = array_map(fn($r) => $r->getType(), $records);
        $this->assertContains(RecordType::A,    $types);
        $this->assertContains(RecordType::AAAA, $types);
    }

    // ── dhcp-mismatch/tag ─────────────────────────────────────────────────────

    public function testDhcpMismatchTagAddsExclusionTagToHost(): void
    {
        $subnet = $this->makeSubnet('tag-net', '10.1.0.0/24');
        $host   = $this->makeHost('roamer');
        $iface  = $this->makeInterface($host, $subnet, 'ee:ff:00:11:22:33');
        $this->em->flush();

        $crawler = $this->getRecommendationsPage();
        $token   = $this->extractToken($crawler, 'csrf-dhcp-mismatch-tag');

        $this->client->request('POST', '/recommendations/apply/dhcp-mismatch/tag', [
            '_token_tag' => $token,
            'ids'        => [$iface->getId()],
        ]);
        $this->assertResponseRedirects('/recommendations');

        $this->em->clear();
        $refreshedHost = $this->em->find(Host::class, $host->getId());
        $tagNames = $refreshedHost->getTags()->map(fn($t) => $t->getName())->toArray();
        $this->assertContains(RecommendationService::DHCP_EXCLUSION_TAG, $tagNames);
    }

    public function testDhcpMismatchTagIsIdempotentForSameHost(): void
    {
        $subnet = $this->makeSubnet('idem-net', '10.1.0.0/24');
        $host   = $this->makeHost('idem-host');
        $iface1 = $this->makeInterface($host, $subnet, 'ff:00:11:22:33:44');
        $iface2 = $this->makeInterface($host, $subnet, 'ff:00:11:22:33:45');
        $this->em->flush();

        $crawler = $this->getRecommendationsPage();
        $token   = $this->extractToken($crawler, 'csrf-dhcp-mismatch-tag');

        $this->client->request('POST', '/recommendations/apply/dhcp-mismatch/tag', [
            '_token_tag' => $token,
            'ids'        => [$iface1->getId(), $iface2->getId()],
        ]);
        $this->assertResponseRedirects('/recommendations');

        $this->em->clear();
        $refreshedHost = $this->em->find(Host::class, $host->getId());
        $dhcpOkTags = $refreshedHost->getTags()->filter(
            fn($t) => $t->getName() === RecommendationService::DHCP_EXCLUSION_TAG
        );
        $this->assertCount(1, $dhcpOkTags, 'Tag should be added only once even when multiple interfaces from the same host are submitted');
    }

    // ── dhcp-mismatch/move ────────────────────────────────────────────────────

    public function testDhcpMismatchMoveChangesInterfaceSubnet(): void
    {
        $subnetA = $this->makeSubnet('move-a', '10.1.0.0/24');
        $subnetB = $this->makeSubnet('move-b', '10.2.0.0/24');
        $host    = $this->makeHost('mover');
        $iface   = $this->makeInterface($host, $subnetA, '00:11:22:33:44:55');
        $iface->setLastDhcpIp('10.2.0.50');
        $this->em->flush();

        $crawler = $this->getRecommendationsPage();
        $token   = $this->extractToken($crawler, 'csrf-dhcp-mismatch-move');

        $this->client->request('POST', '/recommendations/apply/dhcp-mismatch/move', [
            '_token_move' => $token,
            'ids'         => [$iface->getId()],
        ]);
        $this->assertResponseRedirects('/recommendations');

        $this->em->clear();
        $refreshed = $this->em->find(NetworkInterface::class, $iface->getId());
        $this->assertSame($subnetB->getId(), $refreshed->getSubnet()->getId());
    }
}
