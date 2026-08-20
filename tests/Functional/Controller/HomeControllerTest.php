<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\AppWebTestCase;

class HomeControllerTest extends AppWebTestCase
{
    public function testRootRedirectsToHostIndex(): void
    {
        $this->client->request('GET', '/');
        $this->assertResponseRedirects('/hosts');
    }
}
