<?php

namespace App\Controller;

use App\Repository\AppSettingRepository;
use App\Repository\ScheduledTaskRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/worker-queue')]
class WorkerQueueController extends AbstractController
{
    #[Route('', name: 'worker_queue', methods: ['GET'])]
    public function index(Connection $conn, ScheduledTaskRepository $taskRepo, AppSettingRepository $settingRepo): Response
    {
        $tz = $settingRepo->getInstance()->getTimezone() ?? 'UTC';
        $rows = $conn->fetchAllAssociative(
            'SELECT id, queue_name, created_at, available_at, delivered_at, body
             FROM messenger_messages
             ORDER BY created_at DESC
             LIMIT 500'
        );

        $taskNames = [];
        foreach ($taskRepo->findAll() as $task) {
            $taskNames[$task->getId()] = $task->getName();
        }

        $running = [];
        $pending = [];
        $failed  = [];

        foreach ($rows as $row) {
            $row['label'] = $this->parseLabel($row['body'], $taskNames);
            if (str_starts_with($row['queue_name'], 'failed')) {
                $failed[] = $row;
            } elseif ($row['delivered_at'] !== null) {
                $running[] = $row;
            } else {
                $pending[] = $row;
            }
        }

        return $this->render('worker_queue/index.html.twig', [
            'running' => $running,
            'pending' => $pending,
            'failed'  => $failed,
            'tz'      => $tz,
        ]);
    }

    #[Route('/{id}/retry', name: 'worker_queue_retry', methods: ['POST'])]
    public function retry(int $id, Request $request, Connection $conn): Response
    {
        if (!$this->isCsrfTokenValid('wq_retry_' . $id, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('worker_queue');
        }

        $affected = $conn->executeStatement(
            "UPDATE messenger_messages
             SET queue_name = CASE queue_name
                 WHEN 'failed_priority' THEN 'priority'
                 WHEN 'failed_bulk'     THEN 'bulk'
             END,
             available_at = NOW(), delivered_at = NULL
             WHERE id = ? AND queue_name IN ('failed_priority', 'failed_bulk')",
            [$id]
        );

        $this->addFlash(
            $affected ? 'success' : 'warning',
            $affected ? 'Message requeued for retry.' : 'Message not found or already retried.'
        );
        return $this->redirectToRoute('worker_queue');
    }

    #[Route('/purge-failed', name: 'worker_queue_purge_failed', methods: ['POST'])]
    public function purgeFailed(Request $request, Connection $conn): Response
    {
        if (!$this->isCsrfTokenValid('wq_purge_failed', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('worker_queue');
        }

        $count = $conn->executeStatement("DELETE FROM messenger_messages WHERE queue_name IN ('failed_priority', 'failed_bulk')");

        $this->addFlash('success', $count . ' failed ' . ($count === 1 ? 'message' : 'messages') . ' deleted.');
        return $this->redirectToRoute('worker_queue');
    }

    #[Route('/{id}/delete', name: 'worker_queue_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, Connection $conn): Response
    {
        if (!$this->isCsrfTokenValid('wq_delete_' . $id, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('worker_queue');
        }

        $affected = $conn->executeStatement(
            "DELETE FROM messenger_messages WHERE id = ? AND queue_name = 'failed'",
            [$id]
        );

        $this->addFlash(
            $affected ? 'success' : 'warning',
            $affected ? 'Message deleted.' : 'Message not found.'
        );
        return $this->redirectToRoute('worker_queue');
    }

    #[Route('/{id}/cancel', name: 'worker_queue_cancel', methods: ['POST'])]
    public function cancel(int $id, Request $request, Connection $conn): Response
    {
        if (!$this->isCsrfTokenValid('wq_cancel_' . $id, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('worker_queue');
        }

        $affected = $conn->executeStatement(
            "DELETE FROM messenger_messages
             WHERE id = ? AND queue_name IN ('priority', 'bulk') AND delivered_at IS NULL",
            [$id]
        );

        $this->addFlash(
            $affected ? 'success' : 'warning',
            $affected ? 'Message cancelled.' : 'Message not found or already claimed by a worker.'
        );
        return $this->redirectToRoute('worker_queue');
    }

    private function parseLabel(string $body, array $taskNames): string
    {
        $body = stripslashes($body);

        if (!preg_match('/O:\d+:"App\\\\Message\\\\(\w+)"/', $body, $m)) {
            return 'Unknown';
        }

        return match ($m[1]) {
            'RunScheduledTaskMessage' => $this->labelScheduledTask($body, $taskNames),
            'PushClearpassMessage'    => $this->labelClearpass($body),
            'PushDnsMessage'          => 'Push DNS',
            'PushDhcpMessage'         => 'Push DHCP',
            default                   => trim(preg_replace('/([A-Z])/', ' $1', $m[1])),
        };
    }

    private function labelScheduledTask(string $body, array $taskNames): string
    {
        if (preg_match('/"taskId";i:(\d+)/', $body, $m)) {
            $id   = (int) $m[1];
            $name = $taskNames[$id] ?? 'Task #' . $id;
            return 'Run Scheduled Task: ' . $name;
        }
        return 'Run Scheduled Task';
    }

    private function labelClearpass(string $body): string
    {
        if (preg_match('/"mac";s:\d+:"([^"]+)"/', $body, $m)) {
            return 'Push ClearPass (' . $m[1] . ')';
        }
        return 'Push ClearPass (all endpoints)';
    }
}
