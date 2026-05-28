<?php

namespace App\Tests\Functional;

use App\Security\SamlUser;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base class for all functional tests.
 *
 * - Injects a fake SAML user so every request is authenticated.
 * - Wraps each test in a DBAL transaction rolled back in tearDown so the
 *   database is clean after every test without reloading fixtures.
 */
abstract class AppWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->client->disableReboot(); // keep one kernel/connection for the whole test
        // Satisfy stateless CSRF (SameOriginCsrfTokenManager) without JavaScript
        $this->client->setServerParameters(['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        $this->loginAsTestUser();

        $container  = static::getContainer();
        $this->em   = $container->get(EntityManagerInterface::class);
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

    protected function loginAsTestUser(string $email = 'test@example.com'): void
    {
        $user = new SamlUser($email, [
            'firstName' => ['Test'],
            'lastName'  => ['User'],
        ]);
        $this->client->loginUser($user, 'main');
    }

    protected function csrfToken(string $intention): string
    {
        return static::getContainer()
            ->get('security.csrf.token_manager')
            ->getToken($intention)
            ->getValue();
    }

    /**
     * Make a JSON API request. Returns the decoded response body.
     */
    protected function apiRequest(string $method, string $url, array $body = []): mixed
    {
        $this->client->request(
            $method,
            $url,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $body ? json_encode($body) : null
        );

        return json_decode($this->client->getResponse()->getContent(), true);
    }
}
