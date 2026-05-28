<?php

namespace App\DataFixtures;

use App\Entity\Building;
use App\Entity\DhcpServer;
use App\Entity\DnsServer;
use App\Entity\DnsView;
use App\Entity\Domain;
use App\Entity\Host;
use App\Entity\Subnet;
use App\Entity\Tag;
use App\Entity\Vrf;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Minimal data for functional tests. Load with:
 *   php bin/console doctrine:fixtures:load --group=test --env=test
 */
class FunctionalTestFixtures extends Fixture implements FixtureGroupInterface
{
    public static function getGroups(): array
    {
        return ['test'];
    }

    public function load(ObjectManager $manager): void
    {
        $building = (new Building())->setName('Fixture Building');
        $manager->persist($building);

        $vrf = (new Vrf())->setName('fixture-vrf');
        $manager->persist($vrf);

        $tag = (new Tag())->setName('fixture-tag');
        $manager->persist($tag);

        $view = (new DnsView())->setName('fixture-view');
        $manager->persist($view);

        $subnet = (new Subnet())
            ->setName('Fixture Subnet')
            ->setIpv4Cidr('192.168.200.0/24')
            ->setVrf($vrf);
        $manager->persist($subnet);

        $host = (new Host())
            ->setName('fixture-host')
            ->setBuilding($building);
        $manager->persist($host);

        $domain = (new Domain())
            ->setName('fixture.example.com')
            ->addView($view);
        $manager->persist($domain);

        $dnsServer = (new DnsServer())
            ->setName('Fixture DNS Server')
            ->setHostname('ns1.fixture.example.com')
            ->setSshUser('root')
            ->setRemoteZonePath('/etc/bind/zones')
            ->setServerType('primary')
            ->addView($view);
        $manager->persist($dnsServer);

        $dhcpServer = (new DhcpServer())
            ->setName('Fixture DHCP Server')
            ->setHostname('dhcp1.fixture.example.com')
            ->setSshUser('root')
            ->setRemotePath('/etc/kea');
        $manager->persist($dhcpServer);

        $manager->flush();

        $this->addReference('fixture-building', $building);
        $this->addReference('fixture-vrf', $vrf);
        $this->addReference('fixture-tag', $tag);
        $this->addReference('fixture-view', $view);
        $this->addReference('fixture-subnet', $subnet);
        $this->addReference('fixture-host', $host);
        $this->addReference('fixture-domain', $domain);
        $this->addReference('fixture-dns-server', $dnsServer);
        $this->addReference('fixture-dhcp-server', $dhcpServer);
    }
}
