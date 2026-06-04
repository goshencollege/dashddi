<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\AppWebTestCase;

class WorkerQueueControllerTest extends AppWebTestCase
{
    public function testIndexLoads(): void
    {
        $this->client->request('GET', '/worker-queue');
        $this->assertResponseIsSuccessful();
    }

    public function testDiscardDeletesStuckMessage(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at, delivered_at)
             VALUES (?, ?, ?, NOW(), NOW(), NOW())',
            ['{}', '{}', 'priority']
        );
        $id = (int) $conn->lastInsertId();

        // GET the page first to establish a session, then extract the CSRF token from the rendered form.
        $crawler    = $this->client->request('GET', '/worker-queue');
        $csrfInput  = $crawler->filter('form[action="/worker-queue/' . $id . '/discard"] input[name="_token"]');
        $this->assertGreaterThan(0, $csrfInput->count(), 'Discard form should be rendered for the inserted running message');
        $csrfToken  = $csrfInput->attr('value');

        $this->client->request('POST', '/worker-queue/' . $id . '/discard', ['_token' => $csrfToken]);

        $this->assertResponseRedirects('/worker-queue');
        $this->assertSame(
            0,
            (int) $conn->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE id = ?', [$id])
        );
    }

    public function testDiscardDoesNotDeleteFailedQueueMessage(): void
    {
        $conn = $this->em->getConnection();

        // Insert as a running (priority) message so it appears on the page and we can get its CSRF token.
        $conn->executeStatement(
            'INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at, delivered_at)
             VALUES (?, ?, ?, NOW(), NOW(), NOW())',
            ['{}', '{}', 'priority']
        );
        $id = (int) $conn->lastInsertId();

        $crawler   = $this->client->request('GET', '/worker-queue');
        $csrfToken = $crawler->filter('form[action="/worker-queue/' . $id . '/discard"] input[name="_token"]')->attr('value');

        // Reclassify to failed_priority — the discard WHERE clause must now protect it.
        $conn->executeStatement(
            'UPDATE messenger_messages SET queue_name = ? WHERE id = ?',
            ['failed_priority', $id]
        );

        $this->client->request('POST', '/worker-queue/' . $id . '/discard', ['_token' => $csrfToken]);

        $this->assertResponseRedirects('/worker-queue');
        $this->assertSame(
            1,
            (int) $conn->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE id = ?', [$id])
        );
    }
}
