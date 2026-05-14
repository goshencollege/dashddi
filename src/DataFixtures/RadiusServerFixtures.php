<?php

namespace App\DataFixtures;

use App\Entity\RadiusServer;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Yaml\Yaml;

class RadiusServerFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $servers = $this->loadLocalConfig()['radius_servers'] ?? [
            [
                'name'        => 'radius.example.com',
                'hostname'    => 'radius.example.com',
                'ssh_user'    => 'root',
                'remote_path' => '/etc/freeradius/3.0',
            ],
        ];

        foreach ($servers as $data) {
            $server = (new RadiusServer())
                ->setName($data['name'])
                ->setHostname($data['hostname'])
                ->setSshUser($data['ssh_user'] ?? 'root')
                ->setRemotePath($data['remote_path'] ?? '/etc/freeradius/3.0')
                ->setSshPrivateKey($data['ssh_private_key'] ?? null)
                ->setDescription($data['description'] ?? null);

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
