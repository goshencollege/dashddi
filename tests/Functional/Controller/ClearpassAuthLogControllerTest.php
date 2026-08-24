<?php

namespace App\Tests\Functional\Controller;

use App\Entity\UserPreference;
use App\Tests\Functional\AppWebTestCase;

class ClearpassAuthLogControllerTest extends AppWebTestCase
{
    public function testIndexLoads(): void
    {
        $this->client->request('GET', '/clearpass/auth-logs');
        $this->assertResponseIsSuccessful();
    }

    public function testSearchIsSavedToUserPreference(): void
    {
        $this->client->request('GET', '/clearpass/auth-logs?username=jdoe&role=staff');
        $this->assertResponseIsSuccessful();

        $pref = $this->em->getRepository(UserPreference::class)->findByIdentifier('test@example.com');
        $this->assertSame(['username' => 'jdoe', 'role' => 'staff'], $pref->getClearpassAuthLogSearch());
    }

    public function testPlainVisitRedirectsToRestoreSavedSearch(): void
    {
        $this->client->request('GET', '/clearpass/auth-logs?username=jdoe');

        $this->client->request('GET', '/clearpass/auth-logs');
        $this->assertResponseRedirects();
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('value="jdoe"', $this->client->getResponse()->getContent());
    }

    public function testResetClearsSavedSearch(): void
    {
        $this->client->request('GET', '/clearpass/auth-logs?username=jdoe');
        $this->client->request('GET', '/clearpass/auth-logs?reset=1');
        $this->assertResponseRedirects('/clearpass/auth-logs');

        $pref = $this->em->getRepository(UserPreference::class)->findByIdentifier('test@example.com');
        $this->assertNull($pref->getClearpassAuthLogSearch());

        $this->client->request('GET', '/clearpass/auth-logs');
        $this->assertResponseIsSuccessful();
    }
}
