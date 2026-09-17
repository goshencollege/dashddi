<?php

namespace App\Tests\Functional\Api;

use App\Entity\ApiToken;
use App\Entity\Host;
use App\Tests\Functional\AppWebTestCase;

class HostApiControllerTest extends AppWebTestCase
{
    private function makeHost(string $name): Host
    {
        $host = (new Host())->setName($name);
        $this->em->persist($host);
        $this->em->flush();
        return $host;
    }

    public function testIndexReturnsJson(): void
    {
        $this->makeHost('API Host');
        $data = $this->apiRequest('GET', '/api/hosts');
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
    }

    public function testIndexFiltersByName(): void
    {
        $this->makeHost('Unique QQQ Host');
        $data = $this->apiRequest('GET', '/api/hosts?name=Unique+QQQ');
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('Unique QQQ Host', $data[0]['name']);
    }

    public function testShow(): void
    {
        $host = $this->makeHost('Show Host');
        $data = $this->apiRequest('GET', "/api/hosts/{$host->getId()}");
        $this->assertSame($host->getId(), $data['id']);
        $this->assertSame('Show Host', $data['name']);
    }

    public function testCreate(): void
    {
        $data = $this->apiRequest('POST', '/api/hosts', ['name' => 'Created Host', 'room' => '101']);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame('Created Host', $data['name']);
        $this->assertSame('101', $data['room']);
    }

    public function testCreateRequiresName(): void
    {
        $this->apiRequest('POST', '/api/hosts', []);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testUpdate(): void
    {
        $host = $this->makeHost('Patch Host');
        $data = $this->apiRequest('PATCH', "/api/hosts/{$host->getId()}", ['name' => 'Patched Host']);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('Patched Host', $data['name']);
    }

    public function testCreateWithDuid(): void
    {
        $data = $this->apiRequest('POST', '/api/hosts', [
            'name' => 'Duid Host',
            'duid' => 'DUID-LLT:00011234567890abcdef',
        ]);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame('00:01:00:01:12:34:56:78:90:ab:cd:ef', $data['duid']);
        $this->assertSame('DUID-LLT:00011234567890abcdef', $data['duid_display']);
    }

    public function testCreateRejectsMalformedDuid(): void
    {
        $this->apiRequest('POST', '/api/hosts', ['name' => 'Bad Duid Host', 'duid' => 'not-hex']);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateRejectsDuplicateDuid(): void
    {
        $this->apiRequest('POST', '/api/hosts', ['name' => 'Duid Host A', 'duid' => 'aabbccddeeff00112233']);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());

        $this->apiRequest('POST', '/api/hosts', ['name' => 'Duid Host B', 'duid' => 'aabbccddeeff00112233']);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testUpdateSetsAndClearsDuid(): void
    {
        $host = $this->makeHost('Duid Update Host');

        $data = $this->apiRequest('PATCH', "/api/hosts/{$host->getId()}", ['duid' => 'aabbccddeeff00112233']);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('aa:bb:cc:dd:ee:ff:00:11:22:33', $data['duid']);

        $data = $this->apiRequest('PATCH', "/api/hosts/{$host->getId()}", ['duid' => null]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertNull($data['duid']);
    }

    public function testSoftDelete(): void
    {
        $host = $this->makeHost('Delete Host');
        $this->apiRequest('DELETE', "/api/hosts/{$host->getId()}");
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        $data = $this->apiRequest('GET', "/api/hosts/{$host->getId()}");
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testRestore(): void
    {
        $host = $this->makeHost('Restore Host');
        $host->softDeleteWithInterfaces();
        $this->em->flush();

        $data = $this->apiRequest('POST', "/api/hosts/{$host->getId()}/restore");
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertNull($data['deleted_at']);
    }

    public function testDeletedHostsNotInDefaultIndex(): void
    {
        $host = $this->makeHost('Hidden Deleted Host');
        $host->softDeleteWithInterfaces();
        $this->em->flush();

        $data = $this->apiRequest('GET', '/api/hosts?name=Hidden+Deleted+Host');
        $this->assertIsArray($data);
        $this->assertCount(0, $data);
    }

    public function testIndexFreeTextQuery(): void
    {
        $this->makeHost('Unique Freetext Host');
        $this->makeHost('Other Host');
        $data = $this->apiRequest('GET', '/api/hosts?q=Unique+Freetext');
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('Unique Freetext Host', $data[0]['name']);
    }

    public function testIndexStructuredQueryByName(): void
    {
        $this->makeHost('Structured Query Host');
        $this->makeHost('Different Host');
        $data = $this->apiRequest('GET', '/api/hosts?q=' . urlencode('name:Structured*'));
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('Structured Query Host', $data[0]['name']);
    }

    public function testIndexStructuredQueryByRoom(): void
    {
        $host = $this->makeHost('Room Query Host');
        $host->setRoom('A101');
        $this->em->flush();

        $data = $this->apiRequest('GET', '/api/hosts?q=' . urlencode('room:A101'));
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('Room Query Host', $data[0]['name']);
    }

    public function testIndexStructuredQueryExcludesDeletedByDefault(): void
    {
        $host = $this->makeHost('Deleted Structured Host');
        $host->setRoom('Z999');
        $host->softDeleteWithInterfaces();
        $this->em->flush();

        $data = $this->apiRequest('GET', '/api/hosts?q=' . urlencode('room:Z999'));
        $this->assertIsArray($data);
        $this->assertCount(0, $data);
    }

    public function testIndexStructuredQueryDeletedTokenOverridesDeletedParam(): void
    {
        $host = $this->makeHost('Deleted Token Host');
        $host->setRoom('Z998');
        $host->softDeleteWithInterfaces();
        $this->em->flush();

        $data = $this->apiRequest('GET', '/api/hosts?q=' . urlencode('room:Z998 AND deleted:1'));
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('Deleted Token Host', $data[0]['name']);
    }

    public function testIndexQueryTakesPrecedenceOverNameParam(): void
    {
        $this->makeHost('Precedence Match Host');
        $this->makeHost('Precedence Other Host');
        $data = $this->apiRequest('GET', '/api/hosts?q=Precedence+Match&name=Precedence+Other');
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('Precedence Match Host', $data[0]['name']);
    }

    public function testGenerateTokenCreatesNewToken(): void
    {
        $host = $this->makeHost('Token Host');
        $data = $this->apiRequest('POST', "/api/hosts/{$host->getId()}/token");

        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('token', $data);
        $this->assertSame($host->getId(), $data['host_id']);
        $this->assertNotEmpty($data['token']);

        // Verify the token is persisted and linked
        $this->em->clear();
        $found = $this->em->find(Host::class, $host->getId());
        $this->assertNotNull($found->getApiToken());
        $this->assertSame(hash('sha256', $data['token']), $found->getApiToken()->getTokenHash());
    }

    public function testRegenerateTokenReplacesExisting(): void
    {
        $host = $this->makeHost('Regen Host');

        // Create initial token
        $first = $this->apiRequest('POST', "/api/hosts/{$host->getId()}/token");
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $firstId = $first['id'];

        // Regenerate
        $second = $this->apiRequest('POST', "/api/hosts/{$host->getId()}/token");
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());

        // New token should have a different value and different id
        $this->assertNotSame($first['token'], $second['token']);
        $this->assertNotSame($firstId, $second['id']);

        // Old token should no longer exist
        $this->assertNull($this->em->find(ApiToken::class, $firstId));
    }

    public function testRevokeToken(): void
    {
        $host = $this->makeHost('Revoke Token Host');

        // Generate first
        $this->apiRequest('POST', "/api/hosts/{$host->getId()}/token");
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());

        // Revoke
        $this->apiRequest('DELETE', "/api/hosts/{$host->getId()}/token");
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Host should have no token
        $this->em->clear();
        $found = $this->em->find(Host::class, $host->getId());
        $this->assertNull($found->getApiToken());
    }

    public function testRevokeNonExistentTokenReturns404(): void
    {
        $host = $this->makeHost('No Token Host');
        $this->apiRequest('DELETE', "/api/hosts/{$host->getId()}/token");
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }
}
