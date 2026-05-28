<?php

namespace App\Tests\Functional\Api;

use App\Entity\Domain;
use App\Tests\Functional\AppWebTestCase;

class DomainApiControllerTest extends AppWebTestCase
{
    private function makeDomain(string $name): Domain
    {
        $domain = (new Domain())->setName($name);
        $this->em->persist($domain);
        $this->em->flush();
        return $domain;
    }

    public function testIndexReturnsJson(): void
    {
        $this->makeDomain('api.example.com');
        $data = $this->apiRequest('GET', '/api/domains');
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
    }

    public function testIndexFiltersByName(): void
    {
        $this->makeDomain('unique-qqq.example.com');
        $data = $this->apiRequest('GET', '/api/domains?name=unique-qqq');
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('unique-qqq.example.com', $data[0]['name']);
    }

    public function testShow(): void
    {
        $domain = $this->makeDomain('show.example.com');
        $data = $this->apiRequest('GET', "/api/domains/{$domain->getId()}");
        $this->assertSame($domain->getId(), $data['id']);
        $this->assertSame('show.example.com', $data['name']);
    }

    public function testCreate(): void
    {
        $data = $this->apiRequest('POST', '/api/domains', [
            'name'        => 'created.example.com',
            'description' => 'A test domain',
        ]);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame('created.example.com', $data['name']);
        $this->assertSame('A test domain', $data['description']);
    }

    public function testCreateRequiresName(): void
    {
        $this->apiRequest('POST', '/api/domains', []);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testUpdate(): void
    {
        $domain = $this->makeDomain('patch.example.com');
        $data = $this->apiRequest('PATCH', "/api/domains/{$domain->getId()}", ['description' => 'Updated desc']);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('Updated desc', $data['description']);
    }

    public function testDelete(): void
    {
        $domain = $this->makeDomain('delete.example.com');
        $this->apiRequest('DELETE', "/api/domains/{$domain->getId()}");
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }
}
