<?php

namespace App\Tests\Functional\Api;

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
}
