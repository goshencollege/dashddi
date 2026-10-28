<?php

namespace App\Tests\Functional\Controller;

use App\Entity\ApiToken;
use App\Tests\Functional\AppWebTestCase;

class ApiTokenControllerTest extends AppWebTestCase
{
    private function createOtherUsersToken(string $name): ApiToken
    {
        $token = new ApiToken();
        $token->setName($name)->setOwnerIdentifier('someone-else@example.com')
            ->setTokenHash(hash('sha256', $name))->setAllowedRoutes([])->setAllowedCidrs([]);
        $this->em->persist($token);
        $this->em->flush();

        return $token;
    }

    public function testIndexListsGeneralTokensFromAllOwners(): void
    {
        $this->createOtherUsersToken('other-users-token');

        $this->client->request('GET', '/api-tokens');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'other-users-token');
        $this->assertSelectorTextContains('body', 'someone-else@example.com');
    }

    public function testEditIsAllowedOnAnotherUsersToken(): void
    {
        $id = $this->createOtherUsersToken('editable-token')->getId();

        $this->client->request('GET', "/api-tokens/{$id}/edit");
        $this->assertResponseIsSuccessful();
    }

    public function testRegenerateIsAllowedOnAnotherUsersToken(): void
    {
        $id = $this->createOtherUsersToken('regen-token')->getId();

        $crawler = $this->client->request('GET', "/api-tokens/{$id}/edit");
        $this->client->submit(
            $crawler->filter('form[action="/api-tokens/' . $id . '/regenerate"]')->form()
        );
        $this->assertResponseRedirects("/api-tokens/{$id}/created");

        $this->em->clear();
        $found = $this->em->find(ApiToken::class, $id);
        $this->assertNotSame(hash('sha256', 'regen-token'), $found->getTokenHash());
        $this->assertSame('test@example.com', $found->getOwnerIdentifier());
    }

    public function testDeleteIsAllowedOnAnotherUsersToken(): void
    {
        $id = $this->createOtherUsersToken('delete-token')->getId();

        $crawler = $this->client->request('GET', '/api-tokens');
        $this->client->submit(
            $crawler->filter('form[action="/api-tokens/' . $id . '/delete"]')->form()
        );
        $this->assertResponseRedirects('/api-tokens');

        $this->em->clear();
        $this->assertNull($this->em->find(ApiToken::class, $id));
    }
}
