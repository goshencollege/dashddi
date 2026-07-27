<?php

namespace App\Tests\Functional\Security;

use App\Entity\ApiToken;
use App\Entity\Host;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ApiTokenAttributionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->conn = $container->get('doctrine')->getConnection();
        $this->conn->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->conn->isTransactionActive()) {
            $this->conn->rollBack();
        }
        parent::tearDown();
    }

    public function testGeneralTokenAttributesActionsToTokenName(): void
    {
        $rawToken = 'test-attribution-token-abc123';
        $token = (new ApiToken())
            ->setName('MyIntegration')
            ->setTokenHash(hash('sha256', $rawToken))
            ->setOwnerIdentifier('owner@example.com')
            ->setAllowedRoutes(['api_hosts_create'])
            ->setAllowedCidrs(['127.0.0.1']);
        $this->em->persist($token);
        $this->em->flush();

        $this->client->request(
            'POST',
            '/api/hosts',
            [],
            [],
            [
                'CONTENT_TYPE'      => 'application/json',
                'HTTP_ACCEPT'       => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $rawToken,
            ],
            json_encode(['name' => 'Attribution Test Host'])
        );

        $this->assertSame(201, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $host = $this->em->getRepository(Host::class)->findOneBy(['name' => 'Attribution Test Host']);
        $this->assertNotNull($host);
        $this->assertSame('token_MyIntegration', $host->getCreatedBy());
    }

    public function testGeneralTokenDoesNotAttributeToOwner(): void
    {
        $rawToken = 'test-attribution-token-xyz789';
        $token = (new ApiToken())
            ->setName('AnotherToken')
            ->setTokenHash(hash('sha256', $rawToken))
            ->setOwnerIdentifier('should-not-appear@example.com')
            ->setAllowedRoutes(['api_hosts_create'])
            ->setAllowedCidrs(['127.0.0.1']);
        $this->em->persist($token);
        $this->em->flush();

        $this->client->request(
            'POST',
            '/api/hosts',
            [],
            [],
            [
                'CONTENT_TYPE'      => 'application/json',
                'HTTP_ACCEPT'       => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $rawToken,
            ],
            json_encode(['name' => 'Attribution Negative Test Host'])
        );

        $this->assertSame(201, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $host = $this->em->getRepository(Host::class)->findOneBy(['name' => 'Attribution Negative Test Host']);
        $this->assertNotNull($host);
        $this->assertNotSame('should-not-appear@example.com', $host->getCreatedBy());
        $this->assertSame('token_AnotherToken', $host->getCreatedBy());
    }
}
