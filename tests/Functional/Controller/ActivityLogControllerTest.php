<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\AppWebTestCase;

class ActivityLogControllerTest extends AppWebTestCase
{
    public function testIndexLoads(): void
    {
        $this->client->request('GET', '/activity-log');
        $this->assertResponseIsSuccessful();
    }

    public function testIndexWithFilters(): void
    {
        $this->client->request('GET', '/activity-log', [
            'action'      => 'create',
            'entity_type' => 'Host',
            'user'        => 'test@example.com',
            'date_from'   => '2026-01-01',
            'date_to'     => '2026-12-31',
        ]);
        $this->assertResponseIsSuccessful();
    }

    public function testIndexWithEntityNameFilter(): void
    {
        $this->client->request('GET', '/activity-log', [
            'entity_type' => 'Host',
            'entity_name' => 'test-host',
        ]);
        $this->assertResponseIsSuccessful();
    }

    public function testIndexWithHostIdFilter(): void
    {
        $this->client->request('GET', '/activity-log', ['host_id' => '1']);
        $this->assertResponseIsSuccessful();
    }

    public function testIndexWithInvalidPage(): void
    {
        $this->client->request('GET', '/activity-log', ['page' => '-5']);
        $this->assertResponseIsSuccessful();
    }
}
