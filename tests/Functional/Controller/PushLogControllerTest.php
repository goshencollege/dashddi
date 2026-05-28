<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\AppWebTestCase;

class PushLogControllerTest extends AppWebTestCase
{
    public function testIndexLoads(): void
    {
        $this->client->request('GET', '/push-logs');
        $this->assertResponseIsSuccessful();
    }
}
