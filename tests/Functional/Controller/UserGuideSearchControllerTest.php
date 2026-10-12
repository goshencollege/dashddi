<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\AppWebTestCase;

class UserGuideSearchControllerTest extends AppWebTestCase
{
    public function testGuideIndexRenders(): void
    {
        $this->client->request('GET', '/user-guide');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-ug-search-input]');
    }

    public function testSearchReturnsMatchingSections(): void
    {
        $response = $this->apiRequest('GET', '/user-guide/search?q=dnssec');

        $this->assertResponseIsSuccessful();
        $this->assertNotEmpty($response['results']);

        $first = $response['results'][0];
        $this->assertArrayHasKey('pageTitle', $first);
        $this->assertArrayHasKey('sectionTitle', $first);
        $this->assertArrayHasKey('snippet', $first);
        $this->assertStringContainsString('#', $first['url']);
    }

    public function testSearchWithShortQueryReturnsNoResults(): void
    {
        $response = $this->apiRequest('GET', '/user-guide/search?q=a');

        $this->assertResponseIsSuccessful();
        $this->assertSame([], $response['results']);
    }

    public function testSearchWithNoMatchesReturnsEmptyArray(): void
    {
        $response = $this->apiRequest('GET', '/user-guide/search?q=zzzznotfoundatall');

        $this->assertResponseIsSuccessful();
        $this->assertSame([], $response['results']);
    }
}
