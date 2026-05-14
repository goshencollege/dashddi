<?php

namespace App\DataFixtures;

use App\Entity\DhcpServer;
use App\Service\SshKeyService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Yaml\Yaml;

class DhcpServerFixtures extends Fixture
{
    public function __construct(private readonly SshKeyService $sshKeys) {}

    public function load(ObjectManager $manager): void
    {
        $servers = $this->loadLocalConfig()['dhcp_servers'] ?? [
            [
                'name'         => 'kea.example.com',
                'hostname'     => 'kea.example.com',
                'ssh_user'     => 'root',
                'remote_path'  => '/etc/kea',
                'control_url'  => 'http://kea.example.com:8000',
                'control_user' => 'admin',
            ],
        ];

        foreach ($servers as $data) {
            $server = (new DhcpServer())
                ->setName($data['name'])
                ->setHostname($data['hostname'])
                ->setSshUser($data['ssh_user'] ?? 'root')
                ->setRemotePath($data['remote_path'] ?? '/etc/kea')
                ->setControlUrl($data['control_url'] ?? null)
                ->setControlUser($data['control_user'] ?? null)
                ->setControlPassword($data['control_password'] ?? null)
                ->setSshPrivateKey($data['ssh_private_key'] ?? null)
                ->setSshPublicKey(isset($data['ssh_private_key']) ? $this->sshKeys->extractPublicKey($data['ssh_private_key']) : null);

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
