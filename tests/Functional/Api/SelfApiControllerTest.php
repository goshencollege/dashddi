<?php

namespace App\Tests\Functional\Api;

use App\Entity\ApiToken;
use App\Entity\DnsView;
use App\Entity\Domain;
use App\Entity\DomainRecord;
use App\Entity\Host;
use App\Entity\IpAddress;
use App\Entity\NetworkInterface;
use App\Entity\Subnet;
use App\Enum\RecordType;
use App\Tests\Functional\AppWebTestCase;
use App\Validator\TxtRecordValueValidator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

class SelfApiControllerTest extends AppWebTestCase
{
    private KernelBrowser $tokenClient;

    protected function setUp(): void
    {
        parent::setUp();
        // Second client for Bearer-token-authenticated requests (no SAML session).
        // Must use the already-booted kernel directly — createClient() would re-boot and throw.
        $this->tokenClient = new KernelBrowser(static::$kernel);
        $this->tokenClient->disableReboot();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Create a host with one interface. The default IP is 127.0.0.1 because
     * KernelBrowser always reports REMOTE_ADDR as 127.0.0.1 regardless of what
     * is passed in the server array. Use a non-loopback IP only for tests that
     * need the IP check to fail.
     */
    private function makeHostWithIp(string $hostName, string $ip = '127.0.0.1'): array
    {
        $cidr = str_starts_with($ip, '127.') ? '127.0.0.0/24' : '10.7.8.0/24';
        $subnet = (new Subnet())->setName("test-$hostName")->setIpv4Cidr($cidr);
        $this->em->persist($subnet);

        $ipAddr = (new IpAddress())->setAddress($ip)->setSubnet($subnet);
        $this->em->persist($ipAddr);

        $host = (new Host())->setName($hostName);
        $this->em->persist($host);

        $iface = (new NetworkInterface())
            ->setHost($host)
            ->setMacAddress('aa:bb:cc:dd:ee:01')
            ->setSubnet($subnet)
            ->setIpAddress($ipAddr);
        $this->em->persist($iface);
        $this->em->flush();

        return [$host, $iface];
    }

    private function makeHostToken(Host $host, string $raw): ApiToken
    {
        $token = new ApiToken();
        $token->setName('Test host token');
        $token->setOwnerIdentifier('test@example.com');
        $token->setTokenHash(hash('sha256', $raw));
        $token->setHost($host);
        $token->setAllowedRoutes([]);
        $token->setAllowedCidrs([]);
        $this->em->persist($token);
        $this->em->flush();
        return $token;
    }

    private function makeDomainRecord(NetworkInterface $iface, Domain $domain, string $hostname, RecordType $type, string $value): DomainRecord
    {
        $record = new DomainRecord();
        $record->setHostname($hostname);
        $record->setType($type);
        $record->setValue($value);
        $record->setDomain($domain);
        $record->setNetworkInterface($iface);
        $this->em->persist($record);
        $this->em->flush();
        return $record;
    }

    /**
     * Make a Bearer-token-authenticated request via the second test client.
     * REMOTE_ADDR is always 127.0.0.1 in the KernelBrowser environment.
     *
     * Clears the entity manager before each request so that all entity
     * associations (e.g. host.interfaces, iface.ipAddress) are lazy-loaded
     * fresh from the DB rather than using in-memory state that may have been
     * initialized as an empty collection during test setup.
     */
    private function tokenRequest(string $method, string $url, string $raw, array $body = []): mixed
    {
        $this->em->clear();

        $this->tokenClient->request(
            $method,
            $url,
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $raw,
                'CONTENT_TYPE'       => 'application/json',
                'HTTP_ACCEPT'        => 'application/json',
            ],
            $body !== [] ? json_encode($body) : null
        );
        return json_decode($this->tokenClient->getResponse()->getContent(), true);
    }

    // ── GET /api/self/host ────────────────────────────────────────────────────

    public function testHostEndpointReturnsInterfacesAndRecords(): void
    {
        $domain = (new Domain())->setName('self-test.example.com');
        $this->em->persist($domain);

        [$host, $iface] = $this->makeHostWithIp('self-test-host'); // 127.0.0.1
        $raw = bin2hex(random_bytes(32));
        $this->makeHostToken($host, $raw);
        $this->makeDomainRecord($iface, $domain, 'web01', RecordType::A, '127.0.0.1');

        $data = $this->tokenRequest('GET', '/api/self/host', $raw);

        $this->assertSame(200, $this->tokenClient->getResponse()->getStatusCode());
        $this->assertSame($host->getId(), $data['id']);
        $this->assertSame('self-test-host', $data['name']);
        $this->assertCount(1, $data['interfaces']);
        $this->assertSame('127.0.0.1', $data['interfaces'][0]['ipv4']);
        $this->assertCount(1, $data['interfaces'][0]['records']);
        $this->assertSame('web01.self-test.example.com', $data['interfaces'][0]['records'][0]['fqdn']);
    }

    // ── POST /api/self/dns-challenge ──────────────────────────────────────────

    public function testCreateChallengeTxtRecordLinkedToInterface(): void
    {
        $domain = (new Domain())->setName('acme-test.example.com');
        $this->em->persist($domain);

        $view = (new DnsView())->setName('external')->setIsPublic(true);
        $this->em->persist($view);
        $domain->addView($view); // view must be on the domain for the validator to allow it
        $this->em->flush();

        [$host, $iface] = $this->makeHostWithIp('acme-host'); // 127.0.0.1
        $raw = bin2hex(random_bytes(32));
        $this->makeHostToken($host, $raw);

        $sourceRecord = $this->makeDomainRecord($iface, $domain, 'srv01', RecordType::A, '127.0.0.1');
        $sourceRecord->addView($view);
        $this->em->flush();

        $data = $this->tokenRequest('POST', '/api/self/dns-challenge', $raw, [
            'fqdn'       => 'srv01.acme-test.example.com',
            'validation' => 'ACME_TOKEN_VALUE_123',
        ]);

        $this->assertSame(201, $this->tokenClient->getResponse()->getStatusCode());
        $this->assertArrayHasKey('id', $data);
        $this->assertSame('_acme-challenge.srv01', $data['hostname']);

        // Reload from DB and verify interface link, value normalisation, and view inheritance
        $this->em->clear();
        $record = $this->em->find(DomainRecord::class, $data['id']);
        $this->assertNotNull($record);
        $this->assertSame(RecordType::TXT, $record->getType());
        $this->assertSame($iface->getId(), $record->getNetworkInterface()->getId());
        $this->assertSame(TxtRecordValueValidator::normalizeTxtValue('ACME_TOKEN_VALUE_123'), $record->getValue());
        $this->assertCount(1, $record->getViews());
        $this->assertSame($view->getId(), $record->getViews()->first()->getId());
    }

    public function testCreateChallengeForApexRecord(): void
    {
        $domain = (new Domain())->setName('apex-test.example.com');
        $this->em->persist($domain);

        [$host, $iface] = $this->makeHostWithIp('apex-host'); // 127.0.0.1
        $raw = bin2hex(random_bytes(32));
        $this->makeHostToken($host, $raw);
        $this->makeDomainRecord($iface, $domain, '@', RecordType::A, '127.0.0.1');

        $data = $this->tokenRequest('POST', '/api/self/dns-challenge', $raw, [
            'fqdn'       => 'apex-test.example.com',
            'validation' => 'APEX_VALIDATION_TOKEN',
        ]);

        $this->assertSame(201, $this->tokenClient->getResponse()->getStatusCode());
        $this->assertSame('_acme-challenge', $data['hostname']);
    }

    public function testCreateChallengeRejectsUnrelatedFqdn(): void
    {
        // Auth succeeds (127.0.0.1 matches interface), but FQDN ownership check returns 403
        [$host] = $this->makeHostWithIp('restricted-host'); // 127.0.0.1
        $raw = bin2hex(random_bytes(32));
        $this->makeHostToken($host, $raw);

        $data = $this->tokenRequest('POST', '/api/self/dns-challenge', $raw, [
            'fqdn'       => 'other-host.example.com',
            'validation' => 'TOKEN',
        ]);

        $this->assertSame(403, $this->tokenClient->getResponse()->getStatusCode());
    }

    // ── DELETE /api/self/dns-challenge ────────────────────────────────────────

    public function testDeleteChallengeTxtRecord(): void
    {
        $domain = (new Domain())->setName('del-test.example.com');
        $this->em->persist($domain);

        [$host, $iface] = $this->makeHostWithIp('del-host'); // 127.0.0.1
        $raw = bin2hex(random_bytes(32));
        $this->makeHostToken($host, $raw);
        $this->makeDomainRecord($iface, $domain, 'srv', RecordType::A, '127.0.0.1');

        // Create the challenge first
        $this->tokenRequest('POST', '/api/self/dns-challenge', $raw, [
            'fqdn'       => 'srv.del-test.example.com',
            'validation' => 'DEL_TOKEN',
        ]);
        $this->assertSame(201, $this->tokenClient->getResponse()->getStatusCode());

        // Now delete it
        $this->tokenRequest('DELETE', '/api/self/dns-challenge', $raw, [
            'fqdn'       => 'srv.del-test.example.com',
            'validation' => 'DEL_TOKEN',
        ]);
        $this->assertSame(204, $this->tokenClient->getResponse()->getStatusCode());
    }

    // ── Public-view filtering ─────────────────────────────────────────────────

    public function testHostEndpointExcludesInternalOnlyDomainRecords(): void
    {
        // Domain with a view that is NOT marked public → records must be excluded
        $internalView = (new DnsView())->setName('internal-only')->setIsPublic(false);
        $this->em->persist($internalView);

        $internalDomain = (new Domain())->setName('internal.example.com');
        $internalDomain->addView($internalView);
        $this->em->persist($internalDomain);

        // Domain with no views → no restriction, should be included
        $publicDomain = (new Domain())->setName('public.example.com');
        $this->em->persist($publicDomain);

        [$host, $iface] = $this->makeHostWithIp('filter-host'); // 127.0.0.1
        $raw = bin2hex(random_bytes(32));
        $this->makeHostToken($host, $raw);
        $this->makeDomainRecord($iface, $internalDomain, 'srv', RecordType::A, '127.0.0.1');
        $this->makeDomainRecord($iface, $publicDomain, 'srv', RecordType::A, '127.0.0.1');

        $data = $this->tokenRequest('GET', '/api/self/host', $raw);

        $this->assertSame(200, $this->tokenClient->getResponse()->getStatusCode());
        $fqdns = array_column($data['interfaces'][0]['records'], 'fqdn');
        $this->assertContains('srv.public.example.com', $fqdns);
        $this->assertNotContains('srv.internal.example.com', $fqdns);
    }

    public function testCreateChallengeRejectsInternalOnlyDomain(): void
    {
        $internalView = (new DnsView())->setName('int-only')->setIsPublic(false);
        $this->em->persist($internalView);

        $domain = (new Domain())->setName('int.example.com');
        $domain->addView($internalView);
        $this->em->persist($domain);
        $this->em->flush();

        [$host, $iface] = $this->makeHostWithIp('int-host'); // 127.0.0.1
        $raw = bin2hex(random_bytes(32));
        $this->makeHostToken($host, $raw);
        $this->makeDomainRecord($iface, $domain, 'box', RecordType::A, '127.0.0.1');

        $data = $this->tokenRequest('POST', '/api/self/dns-challenge', $raw, [
            'fqdn'       => 'box.int.example.com',
            'validation' => 'TOKEN',
        ]);

        $this->assertSame(422, $this->tokenClient->getResponse()->getStatusCode());
        $this->assertStringContainsString('no public views', $data['error']);
    }

    public function testChallengeTxtRecordGetsAllDomainViews(): void
    {
        // Domain with two views: one internal (not public), one external (public).
        // The source A record is only in the internal view.
        // The challenge record must get BOTH domain views.
        $internalView = (new DnsView())->setName('challenge-internal')->setIsPublic(false);
        $externalView = (new DnsView())->setName('challenge-external')->setIsPublic(true);
        $this->em->persist($internalView);
        $this->em->persist($externalView);

        $domain = (new Domain())->setName('mixed.example.com');
        $domain->addView($internalView);
        $domain->addView($externalView);
        $this->em->persist($domain);
        $this->em->flush();

        [$host, $iface] = $this->makeHostWithIp('mixed-host'); // 127.0.0.1
        $raw = bin2hex(random_bytes(32));
        $this->makeHostToken($host, $raw);

        // Source A record is only in the internal view
        $sourceRecord = $this->makeDomainRecord($iface, $domain, 'box', RecordType::A, '127.0.0.1');
        $sourceRecord->addView($internalView);
        $this->em->flush();

        $data = $this->tokenRequest('POST', '/api/self/dns-challenge', $raw, [
            'fqdn'       => 'box.mixed.example.com',
            'validation' => 'MIXED_TOKEN',
        ]);

        $this->assertSame(201, $this->tokenClient->getResponse()->getStatusCode());

        // Challenge record must have BOTH domain views, not just the internal one
        $this->em->clear();
        $record = $this->em->find(DomainRecord::class, $data['id']);
        $this->assertNotNull($record);
        $challengeViewIds = $record->getViews()->map(fn($v) => $v->getId())->toArray();
        $this->assertContains($internalView->getId(), $challengeViewIds);
        $this->assertContains($externalView->getId(), $challengeViewIds);
        $this->assertCount(2, $challengeViewIds);
    }

    // ── Security ──────────────────────────────────────────────────────────────

    public function testHostScopedTokenCannotAccessGeneralRoutes(): void
    {
        // IP passes (127.0.0.1 matches interface); route restriction fires → 401
        [$host] = $this->makeHostWithIp('sec-host'); // 127.0.0.1
        $raw = bin2hex(random_bytes(32));
        $this->makeHostToken($host, $raw);

        $this->tokenRequest('GET', '/api/hosts', $raw);
        $this->assertSame(401, $this->tokenClient->getResponse()->getStatusCode());
    }

    public function testRequestFromWrongIpIsRejected(): void
    {
        // Interface IP is 10.7.8.15; test client reports 127.0.0.1 → IP check fails → 401
        [$host] = $this->makeHostWithIp('ip-host', '10.7.8.15');
        $raw = bin2hex(random_bytes(32));
        $this->makeHostToken($host, $raw);

        $this->tokenRequest('GET', '/api/self/host', $raw);
        $this->assertSame(401, $this->tokenClient->getResponse()->getStatusCode());
    }

    // ── Parent-domain fallback for private subdomains ─────────────────────────

    public function testCreateChallengeForPrivateSubdomainViaParentDomain(): void
    {
        // Public parent domain
        $publicView = (new DnsView())->setName('parent-pub-view')->setIsPublic(true);
        $this->em->persist($publicView);
        $parentDomain = (new Domain())->setName('parent-fallback.example.com');
        $parentDomain->addView($publicView);
        $this->em->persist($parentDomain);

        // Private child domain — no public view, no parent lookup would succeed
        $privateView = (new DnsView())->setName('child-priv-view')->setIsPublic(false);
        $this->em->persist($privateView);
        $childDomain = (new Domain())->setName('internal.parent-fallback.example.com');
        $childDomain->addView($privateView);
        $this->em->persist($childDomain);
        $this->em->flush();

        [$host, $iface] = $this->makeHostWithIp('fallback-host'); // 127.0.0.1
        $raw = bin2hex(random_bytes(32));
        $this->makeHostToken($host, $raw);
        $this->makeDomainRecord($iface, $childDomain, 'box', RecordType::A, '127.0.0.1');

        $data = $this->tokenRequest('POST', '/api/self/dns-challenge', $raw, [
            'fqdn'       => 'box.internal.parent-fallback.example.com',
            'validation' => 'PARENT_FALLBACK_TOKEN',
        ]);

        $this->assertSame(201, $this->tokenClient->getResponse()->getStatusCode());
        $this->assertSame('_acme-challenge.box.internal', $data['hostname']);
        $this->assertSame('parent-fallback.example.com', $data['domain']);

        // Reload from DB: record must live in the parent domain with the multipart label
        $this->em->clear();
        $record = $this->em->find(DomainRecord::class, $data['id']);
        $this->assertNotNull($record);
        $this->assertSame('_acme-challenge.box.internal', $record->getHostname());
        $this->assertSame($parentDomain->getId(), $record->getDomain()->getId());
        $this->assertSame(RecordType::TXT, $record->getType());
        $viewIds = $record->getViews()->map(fn($v) => $v->getId())->toArray();
        $this->assertContains($publicView->getId(), $viewIds);
        $this->assertCount(1, $viewIds);
    }

    public function testCreateChallengeForViewlessDomainFallsBackToPublicParent(): void
    {
        // View-less domain — exists in DashDDI for IPAM only; DNS managed externally (e.g. AD)
        $childDomain = (new Domain())->setName('ad.viewless-parent.example.com');
        $this->em->persist($childDomain);

        // Public parent domain managed by DashDDI
        $publicView = (new DnsView())->setName('vl-parent-pub')->setIsPublic(true);
        $this->em->persist($publicView);
        $parentDomain = (new Domain())->setName('viewless-parent.example.com');
        $parentDomain->addView($publicView);
        $this->em->persist($parentDomain);
        $this->em->flush();

        [$host, $iface] = $this->makeHostWithIp('ad-host'); // 127.0.0.1
        $raw = bin2hex(random_bytes(32));
        $this->makeHostToken($host, $raw);
        $this->makeDomainRecord($iface, $childDomain, 'srv', RecordType::A, '127.0.0.1');

        $data = $this->tokenRequest('POST', '/api/self/dns-challenge', $raw, [
            'fqdn'       => 'srv.ad.viewless-parent.example.com',
            'validation' => 'AD_FALLBACK_TOKEN',
        ]);

        $this->assertSame(201, $this->tokenClient->getResponse()->getStatusCode());
        $this->assertSame('_acme-challenge.srv.ad', $data['hostname']);
        $this->assertSame('viewless-parent.example.com', $data['domain']);

        $this->em->clear();
        $record = $this->em->find(DomainRecord::class, $data['id']);
        $this->assertNotNull($record);
        $this->assertSame($parentDomain->getId(), $record->getDomain()->getId());
        $viewIds = $record->getViews()->map(fn($v) => $v->getId())->toArray();
        $this->assertContains($publicView->getId(), $viewIds);
        $this->assertCount(1, $viewIds);
    }

    public function testDeleteChallengeForPrivateSubdomainViaParentDomain(): void
    {
        $publicView = (new DnsView())->setName('del-parent-pub')->setIsPublic(true);
        $this->em->persist($publicView);
        $parentDomain = (new Domain())->setName('del-parent.example.com');
        $parentDomain->addView($publicView);
        $this->em->persist($parentDomain);

        $privateView = (new DnsView())->setName('del-child-priv')->setIsPublic(false);
        $this->em->persist($privateView);
        $childDomain = (new Domain())->setName('internal.del-parent.example.com');
        $childDomain->addView($privateView);
        $this->em->persist($childDomain);
        $this->em->flush();

        [$host, $iface] = $this->makeHostWithIp('del-fallback-host'); // 127.0.0.1
        $raw = bin2hex(random_bytes(32));
        $this->makeHostToken($host, $raw);
        $this->makeDomainRecord($iface, $childDomain, 'srv', RecordType::A, '127.0.0.1');

        // Create the challenge via parent domain fallback
        $createData = $this->tokenRequest('POST', '/api/self/dns-challenge', $raw, [
            'fqdn'       => 'srv.internal.del-parent.example.com',
            'validation' => 'DEL_PARENT_TOKEN',
        ]);
        $this->assertSame(201, $this->tokenClient->getResponse()->getStatusCode());
        $recordId = $createData['id'];

        // Delete it
        $this->tokenRequest('DELETE', '/api/self/dns-challenge', $raw, [
            'fqdn'       => 'srv.internal.del-parent.example.com',
            'validation' => 'DEL_PARENT_TOKEN',
        ]);
        $this->assertSame(204, $this->tokenClient->getResponse()->getStatusCode());

        // Confirm it's gone
        $this->em->clear();
        $this->assertNull($this->em->find(DomainRecord::class, $recordId));
    }

    public function testHostEndpointIncludesPrivateSubdomainWithPublicParent(): void
    {
        $publicView = (new DnsView())->setName('host-ep-pub')->setIsPublic(true);
        $this->em->persist($publicView);
        $parentDomain = (new Domain())->setName('listing-parent.example.com');
        $parentDomain->addView($publicView);
        $this->em->persist($parentDomain);

        $privateView = (new DnsView())->setName('host-ep-priv')->setIsPublic(false);
        $this->em->persist($privateView);
        $childDomain = (new Domain())->setName('internal.listing-parent.example.com');
        $childDomain->addView($privateView);
        $this->em->persist($childDomain);
        $this->em->flush();

        [$host, $iface] = $this->makeHostWithIp('listing-host'); // 127.0.0.1
        $raw = bin2hex(random_bytes(32));
        $this->makeHostToken($host, $raw);
        $this->makeDomainRecord($iface, $parentDomain, 'pub', RecordType::A, '127.0.0.1');
        $this->makeDomainRecord($iface, $childDomain, 'priv', RecordType::A, '127.0.0.1');

        $data = $this->tokenRequest('GET', '/api/self/host', $raw);

        $this->assertSame(200, $this->tokenClient->getResponse()->getStatusCode());
        $fqdns = array_column($data['interfaces'][0]['records'], 'fqdn');
        $this->assertContains('pub.listing-parent.example.com', $fqdns);
        $this->assertContains('priv.internal.listing-parent.example.com', $fqdns);
    }
}
