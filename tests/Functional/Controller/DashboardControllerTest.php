<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\AppWebTestCase;

class DashboardControllerTest extends AppWebTestCase
{
    public function testDashboardLoads(): void
    {
        $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();
    }
}
