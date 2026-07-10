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
use App\Tests\Functional\AppWebTestCase;

class DomainRecordControllerTest extends AppWebTestCase
{
    private function makeSubnet(string $cidr = '10.1.0.0/24'): Subnet
    {
        $subnet = (new Subnet())->setName('test-subnet')->setIpv4Cidr($cidr);
        $this->em->persist($subnet);
        $this->em->flush();
        return $subnet;
    }

    private function makeDomain(string $name = 'test.example.com'): Domain
    {
        $domain = (new Domain())->setName($name);
        $this->em->persist($domain);
        $this->em->flush();
        return $domain;
    }

    private function makeInterface(Subnet $subnet, bool $withIpv6 = true): NetworkInterface
    {
        $host = (new Host())->setName('test-host');
        $this->em->persist($host);

        $ip = (new IpAddress())->setAddress('10.1.0.50')->setSubnet($subnet);
        $this->em->persist($ip);

        $iface = (new NetworkInterface())
            ->setHost($host)
            ->setMacAddress('aa:bb:cc:dd:ee:01')
            ->setSubnet($subnet)
            ->setIpAddress($ip);

        if ($withIpv6) {
            $ipv6 = (new Ipv6Address())->setAddress('2001:db8::1')->setSubnet($subnet);
            $this->em->persist($ipv6);
            $iface->setIpv6Address($ipv6);
        }

        $this->em->persist($iface);
        $this->em->flush();
        return $iface;
    }

    public function testAplusAaaaCreatesAandAaaaRecord(): void
    {
        $subnet = $this->makeSubnet();
        $domain = $this->makeDomain();
        $iface  = $this->makeInterface($subnet, withIpv6: true);

        $crawler = $this->client->request('GET', "/interfaces/{$iface->getId()}/dns-records/new");
        $this->assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form')->form(), [
            'domain_record[hostname]' => 'dual',
            'domain_record[type]'     => 'A+AAAA',
            'domain_record[domain]'   => $domain->getId(),
        ]);
        $this->assertResponseRedirects();

        $records = $this->em->getRepository(DomainRecord::class)->findBy([
            'networkInterface' => $iface,
            'hostname'         => 'dual',
        ]);

        $types = array_map(fn($r) => $r->getType(), $records);
        $this->assertCount(2, $records, 'A+AAAA should create exactly two records');
        $this->assertContains(RecordType::A, $types, 'A record should be created');
        $this->assertContains(RecordType::AAAA, $types, 'AAAA record should be created');
    }

    public function testAaaaCompanionSkippedWhenNoIpv6(): void
    {
        $subnet = $this->makeSubnet();
        $domain = $this->makeDomain('noipv6.example.com');
        $iface  = $this->makeInterface($subnet, withIpv6: false);

        $crawler = $this->client->request('GET', "/interfaces/{$iface->getId()}/dns-records/new");
        $this->assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form')->form(), [
            'domain_record[hostname]' => 'v4only',
            'domain_record[type]'     => 'A+AAAA',
            'domain_record[domain]'   => $domain->getId(),
        ]);
        $this->assertResponseRedirects();

        $records = $this->em->getRepository(DomainRecord::class)->findBy([
            'networkInterface' => $iface,
            'hostname'         => 'v4only',
        ]);

        $this->assertCount(1, $records, 'Only the A record should be created when no IPv6 address is present');
        $this->assertSame(RecordType::A, $records[0]->getType());
    }
}
