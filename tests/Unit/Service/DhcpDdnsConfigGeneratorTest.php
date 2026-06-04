<?php

namespace App\Tests\Unit\Service;

use App\Entity\DnsServer;
use App\Entity\Domain;
use App\Entity\Subnet;
use App\Enum\TsigAlgorithm;
use App\Repository\DomainRepository;
use App\Repository\SubnetRepository;
use App\Service\DhcpDdnsConfigGenerator;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

class DhcpDdnsConfigGeneratorTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeServer(string $name, ?TsigAlgorithm $algo, ?string $secret, string $hostname = '10.0.0.1'): DnsServer
    {
        $server = (new DnsServer())->setName($name)->setHostname($hostname);
        if ($algo !== null) {
            $server->setDdnsAlgorithm($algo);
        }
        if ($secret !== null) {
            $server->setDdnsSecret($secret);
        }
        return $server;
    }

    private function makeDomain(string $name, DnsServer $server): Domain
    {
        return (new Domain())
            ->setName($name)
            ->setDdnsEnabled(true)
            ->setDdnsDnsServer($server);
    }

    private function makeSubnet(string $cidr, Domain $domain): Subnet
    {
        return (new Subnet())
            ->setName('test')
            ->setIpv4Cidr($cidr)
            ->setDdnsDomain($domain);
    }

    private function makeGenerator(array $domains, array $subnets): DhcpDdnsConfigGenerator
    {
        $subnetQuery = $this->createStub(Query::class);
        $subnetQuery->method('getResult')->willReturn($subnets);

        $subnetQb = $this->createStub(QueryBuilder::class);
        $subnetQb->method('join')->willReturnSelf();
        $subnetQb->method('getQuery')->willReturn($subnetQuery);

        $domainRepo = $this->createStub(DomainRepository::class);
        $domainRepo->method('findBy')->willReturn($domains);

        $subnetRepo = $this->createStub(SubnetRepository::class);
        $subnetRepo->method('createQueryBuilder')->willReturn($subnetQb);

        return new DhcpDdnsConfigGenerator($domainRepo, $subnetRepo);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function testEmptyConfigHasCorrectStructure(): void
    {
        $config = $this->makeGenerator([], [])->generateConfig();
        $ddns   = $config['DhcpDdns'];

        $this->assertSame('127.0.0.1', $ddns['ip-address']);
        $this->assertSame(53001, $ddns['port']);
        $this->assertSame('unix', $ddns['control-socket']['socket-type']);
        $this->assertSame('/var/run/kea/kea-ddns-ctrl-socket', $ddns['control-socket']['socket-name']);
        $this->assertSame([], $ddns['tsig-keys']);
        $this->assertSame([], $ddns['forward-ddns']['ddns-domains']);
        $this->assertSame([], $ddns['reverse-ddns']['ddns-domains']);
        $this->assertArrayHasKey('loggers', $ddns);
    }

    public function testForwardDomainAndTsigKeyAdded(): void
    {
        $server = $this->makeServer('bind-primary', TsigAlgorithm::HmacSha256, 'abc123==');
        $domain = $this->makeDomain('example.com', $server);

        $config = $this->makeGenerator([$domain], [])->generateConfig();
        $ddns   = $config['DhcpDdns'];

        $this->assertCount(1, $ddns['tsig-keys']);
        $key = $ddns['tsig-keys'][0];
        $this->assertSame('ddns-bind-primary', $key['name']);
        $this->assertSame('HMAC-SHA256', $key['algorithm']);
        $this->assertSame('abc123==', $key['secret']);

        $this->assertCount(1, $ddns['forward-ddns']['ddns-domains']);
        $fwd = $ddns['forward-ddns']['ddns-domains'][0];
        $this->assertSame('example.com.', $fwd['name']);
        $this->assertSame('ddns-bind-primary', $fwd['key-name']);
        $this->assertSame('10.0.0.1', $fwd['dns-servers'][0]['ip-address']);
        $this->assertSame(53, $fwd['dns-servers'][0]['port']);
    }

    public function testForwardDomainNameAlwaysHasTrailingDot(): void
    {
        $server = $this->makeServer('ns', TsigAlgorithm::HmacSha256, 'secret');
        $domain = $this->makeDomain('example.com.', $server); // already has trailing dot

        $config = $this->makeGenerator([$domain], [])->generateConfig();
        $fwd    = $config['DhcpDdns']['forward-ddns']['ddns-domains'][0];

        $this->assertSame('example.com.', $fwd['name']);
    }

    public function testReverseZoneAdded(): void
    {
        $server = $this->makeServer('bind-primary', TsigAlgorithm::HmacSha256, 'secret==');
        $domain = $this->makeDomain('example.com', $server);
        $subnet = $this->makeSubnet('192.168.1.0/24', $domain);

        $config = $this->makeGenerator([], [$subnet])->generateConfig();
        $ddns   = $config['DhcpDdns'];

        $this->assertCount(1, $ddns['tsig-keys']);
        $this->assertCount(1, $ddns['reverse-ddns']['ddns-domains']);
        $rev = $ddns['reverse-ddns']['ddns-domains'][0];
        $this->assertSame('1.168.192.in-addr.arpa.', $rev['name']);
        $this->assertSame('ddns-bind-primary', $rev['key-name']);
    }

    public function testDomainWithMissingAlgorithmSkipped(): void
    {
        $server = $this->makeServer('ns', null, 'secret');
        $domain = $this->makeDomain('example.com', $server);

        $config = $this->makeGenerator([$domain], [])->generateConfig();

        $this->assertCount(0, $config['DhcpDdns']['tsig-keys']);
        $this->assertCount(0, $config['DhcpDdns']['forward-ddns']['ddns-domains']);
    }

    public function testDomainWithMissingSecretSkipped(): void
    {
        $server = $this->makeServer('ns', TsigAlgorithm::HmacSha256, null);
        $domain = $this->makeDomain('example.com', $server);

        $config = $this->makeGenerator([$domain], [])->generateConfig();

        $this->assertCount(0, $config['DhcpDdns']['tsig-keys']);
        $this->assertCount(0, $config['DhcpDdns']['forward-ddns']['ddns-domains']);
    }

    public function testDomainWithNoDnsServerSkipped(): void
    {
        $domain = (new Domain())->setName('example.com')->setDdnsEnabled(true);

        $config = $this->makeGenerator([$domain], [])->generateConfig();

        $this->assertCount(0, $config['DhcpDdns']['tsig-keys']);
        $this->assertCount(0, $config['DhcpDdns']['forward-ddns']['ddns-domains']);
    }

    public function testTwoDomainsOnSameServerProduceSingleTsigKey(): void
    {
        $server  = $this->makeServer('ns', TsigAlgorithm::HmacSha256, 'secret');
        $domain1 = $this->makeDomain('example.com', $server);
        $domain2 = $this->makeDomain('example.org', $server);

        $config = $this->makeGenerator([$domain1, $domain2], [])->generateConfig();

        $this->assertCount(1, $config['DhcpDdns']['tsig-keys']);
        $this->assertCount(2, $config['DhcpDdns']['forward-ddns']['ddns-domains']);
    }

    public function testSubnetWithIncompleteServerSkipped(): void
    {
        $server = $this->makeServer('ns', null, null);
        $domain = (new Domain())->setName('example.com')->setDdnsEnabled(true)->setDdnsDnsServer($server);
        $subnet = $this->makeSubnet('10.0.0.0/8', $domain);

        $config = $this->makeGenerator([], [$subnet])->generateConfig();

        $this->assertCount(0, $config['DhcpDdns']['tsig-keys']);
        $this->assertCount(0, $config['DhcpDdns']['reverse-ddns']['ddns-domains']);
    }
}
