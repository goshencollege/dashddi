<?php

namespace App\DataFixtures;

use App\Entity\DnsServer;
use App\Entity\DnsView;
use App\Service\SshKeyService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Yaml\Yaml;

class DnsServerFixtures extends Fixture
{
    public function __construct(private readonly SshKeyService $sshKeys) {}

    public function load(ObjectManager $manager): void
    {
        $servers = $this->loadLocalConfig()['dns_servers'] ?? [
            [
                'name'             => 'ns1.example.com',
                'hostname'         => '192.0.2.1',
                'ssh_user'         => 'root',
                'remote_zone_path' => '/etc/bind/zones',
                'server_type'      => 'primary',
            ],
            [
                'name'             => 'ns2.example.com',
                'hostname'         => '192.0.2.2',
                'ssh_user'         => 'root',
                'remote_zone_path' => '/etc/bind/zones',
                'server_type'      => 'secondary',
                'primary_hostname' => '192.0.2.1',
            ],
        ];

        $defaultView = (new DnsView())
            ->setName('default')
            ->setDescription('Default view (all clients)');

        $manager->persist($defaultView);

        foreach ($servers as $data) {
            $server = (new DnsServer())
                ->setName($data['name'])
                ->setHostname($data['hostname'])
                ->setSshUser($data['ssh_user'] ?? 'root')
                ->setRemoteZonePath($data['remote_zone_path'] ?? '/etc/bind/zones')
                ->setServerType($data['server_type'] ?? 'primary')
                ->setPrimaryHostname($data['primary_hostname'] ?? null)
                ->setKeyDirectory($data['key_directory'] ?? null)
                ->setSshPrivateKey($data['ssh_private_key'] ?? null)
                ->setSshPublicKey(isset($data['ssh_private_key']) ? $this->sshKeys->extractPublicKey($data['ssh_private_key']) : null);
            $server->addView($defaultView);

            $manager->persist($server);
        }

        $manager->flush();
    }

    private function loadLocalConfig(): array
    {
        $path = dirname(__DIR__, 2) . '/fixtures.local.yaml';
        return file_exists($path) ? Yaml::parseFile($path) : [];
    }
}
