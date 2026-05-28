<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Tag;
use App\Tests\Functional\AppWebTestCase;

class TagControllerTest extends AppWebTestCase
{
    public function testIndexLoads(): void
    {
        $this->client->request('GET', '/tags');
        $this->assertResponseIsSuccessful();
    }

    public function testNewFormLoads(): void
    {
        $this->client->request('GET', '/tags/new');
        $this->assertResponseIsSuccessful();
    }

    public function testCreate(): void
    {
        $crawler = $this->client->request('GET', '/tags/new');
        $this->client->submit($crawler->filter('form')->form(), [
            'tag[name]' => 'new-functional-tag',
        ]);
        $this->assertResponseRedirects();
    }

    public function testEditFormLoads(): void
    {
        $tag = (new Tag())->setName('edit-tag');
        $this->em->persist($tag);
        $this->em->flush();

        $this->client->request('GET', "/tags/{$tag->getId()}/edit");
        $this->assertResponseIsSuccessful();
    }

    public function testUpdate(): void
    {
        $tag = (new Tag())->setName('update-tag');
        $this->em->persist($tag);
        $this->em->flush();

        $crawler = $this->client->request('GET', "/tags/{$tag->getId()}/edit");
        $this->client->submit($crawler->filter('form')->form(), [
            'tag[name]' => 'updated-tag',
        ]);
        $this->assertResponseRedirects();
    }

    public function testDelete(): void
    {
        $tag = (new Tag())->setName('delete-tag');
        $this->em->persist($tag);
        $this->em->flush();

        $id      = $tag->getId();
        $crawler = $this->client->request('GET', '/tags');
        $this->client->submit(
            $crawler->filter('form[action="/tags/' . $id . '/delete"]')->form()
        );
        $this->assertResponseRedirects();
    }
}
