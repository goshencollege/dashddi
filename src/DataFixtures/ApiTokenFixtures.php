<?php

namespace App\DataFixtures;

use App\Entity\ApiToken;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Yaml\Yaml;

class ApiTokenFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $tokens = $this->loadLocalConfig()['api_tokens'] ?? [];

        if (empty($tokens)) {
            return;
        }

        foreach ($tokens as $data) {
            $token = new ApiToken();
            $token->setName($data['name']);
            $token->setTokenHash(hash('sha256', $data['token']));
            $token->setOwnerIdentifier($data['owner'] ?? 'dev@example.com');
            $token->setAllowedRoutes($data['allowed_routes'] ?? []);
            if (!empty($data['expires_at'])) {
                $token->setExpiresAt(new \DateTimeImmutable($data['expires_at']));
            }

            $manager->persist($token);
        }

        $manager->flush();

        echo PHP_EOL . '  API tokens (plain-text values for use in dev):' . PHP_EOL;
        foreach ($tokens as $data) {
            echo sprintf('    %-20s %s', $data['name'] . ':', $data['token']) . PHP_EOL;
        }
        echo PHP_EOL;
    }

    private function loadLocalConfig(): array
    {
        $path = dirname(__DIR__, 2) . '/fixtures.local.yaml';
        return file_exists($path) ? Yaml::parseFile($path) : [];
    }
}
