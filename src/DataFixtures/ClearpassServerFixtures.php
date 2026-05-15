<?php

namespace App\DataFixtures;

use App\Entity\ClearpassServer;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Yaml\Yaml;

class ClearpassServerFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $servers = $this->loadLocalConfig()['clearpass_servers'] ?? [];

        foreach ($servers as $data) {
            $server = (new ClearpassServer())
                ->setName($data['name'])
                ->setApiUrl($data['api_url'])
                ->setClientId($data['client_id'])
                ->setClientSecret($data['client_secret'])
                ->setVerifyTls($data['verify_tls'] ?? true)
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
