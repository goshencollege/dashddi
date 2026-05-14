<?php

namespace App\DataFixtures;

use App\Entity\RadiusClient;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Yaml\Yaml;

class RadiusClientFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $clients = $this->loadLocalConfig()['radius_clients'] ?? [
            [
                'name'      => 'switch-01',
                'nasname'   => '192.168.1.1',
                'shortname' => 'switch-01',
                'secret'    => 'testing123',
                'enabled'   => true,
            ],
        ];

        foreach ($clients as $data) {
            $client = (new RadiusClient())
                ->setName($data['name'])
                ->setNasname($data['nasname'])
                ->setShortname($data['shortname'] ?? null)
                ->setSecret($data['secret'])
                ->setDescription($data['description'] ?? null)
                ->setEnabled($data['enabled'] ?? true);

            $manager->persist($client);
        }

        $manager->flush();
    }

    private function loadLocalConfig(): array
    {
        $path = dirname(__DIR__, 2) . '/fixtures.local.yaml';
        return file_exists($path) ? Yaml::parseFile($path) : [];
    }
}
