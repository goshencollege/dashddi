<?php

namespace App\Tests\Functional\Api;

use App\Entity\Building;
use App\Tests\Functional\AppWebTestCase;

class BuildingApiControllerTest extends AppWebTestCase
{
    private function makeBuilding(string $name): Building
    {
        $building = (new Building())->setName($name);
        $this->em->persist($building);
        $this->em->flush();
        return $building;
    }

    public function testIndexReturnsJson(): void
    {
        $this->makeBuilding('API Building');
        $data = $this->apiRequest('GET', '/api/buildings');
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
    }

    public function testIndexFiltersByName(): void
    {
        $this->makeBuilding('Unique XYZ Building');
        $data = $this->apiRequest('GET', '/api/buildings?name=Unique+XYZ');
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('Unique XYZ Building', $data[0]['name']);
    }

    public function testShow(): void
    {
        $building = $this->makeBuilding('Show Building');
        $data = $this->apiRequest('GET', "/api/buildings/{$building->getId()}");
        $this->assertSame($building->getId(), $data['id']);
        $this->assertSame('Show Building', $data['name']);
    }

    public function testCreate(): void
    {
        $data = $this->apiRequest('POST', '/api/buildings', ['name' => 'Created Building', 'description' => 'desc']);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame('Created Building', $data['name']);
        $this->assertSame('desc', $data['description']);
        $this->assertArrayHasKey('id', $data);
    }

    public function testCreateRequiresName(): void
    {
        $this->apiRequest('POST', '/api/buildings', []);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testUpdate(): void
    {
        $building = $this->makeBuilding('Update Building');
        $data = $this->apiRequest('PATCH', "/api/buildings/{$building->getId()}", ['name' => 'Patched Building']);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('Patched Building', $data['name']);
    }

    public function testDelete(): void
    {
        $building = $this->makeBuilding('Delete Building');
        $this->apiRequest('DELETE', "/api/buildings/{$building->getId()}");
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }
}
