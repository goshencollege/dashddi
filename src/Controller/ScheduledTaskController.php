<?php

namespace App\Controller;

use App\Entity\ScheduledTask;
use App\Form\ScheduledTaskFormType;
use App\Message\RunScheduledTaskMessage;
use App\Repository\AppSettingRepository;
use App\Repository\ScheduledTaskRepository;
use Cron\CronExpression;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class ScheduledTaskController extends AbstractController
{
    #[Route('/scheduler', name: 'scheduler_index', methods: ['GET'])]
    public function index(ScheduledTaskRepository $repo, AppSettingRepository $settingRepo): Response
    {
        $tasks   = $repo->findBy([], ['id' => 'ASC']);
        $nextRun = [];
        $tz      = new \DateTimeZone($settingRepo->getInstance()->getTimezone() ?? 'UTC');

        foreach ($tasks as $task) {
            try {
                $cron = new CronExpression($task->getCronExpression());
                $nextRun[$task->getId()] = $cron->getNextRunDate('now', 0, false, $tz->getName());
            } catch (\Exception) {
                $nextRun[$task->getId()] = null;
            }
        }

        $heartbeatFile   = $this->getParameter('kernel.project_dir') . '/var/scheduler-heartbeat';
        $lastHeartbeat   = file_exists($heartbeatFile) ? (int) file_get_contents($heartbeatFile) : null;
        $schedulerStale  = $lastHeartbeat === null || (time() - $lastHeartbeat) > 3600;

        return $this->render('scheduled_task/index.html.twig', [
            'tasks'          => $tasks,
            'nextRun'        => $nextRun,
            'schedulerStale' => $schedulerStale,
            'lastHeartbeat'  => $lastHeartbeat !== null ? \DateTimeImmutable::createFromFormat('U', (string) $lastHeartbeat) : null,
        ]);
    }

    #[Route('/scheduler/{id}/edit', name: 'scheduler_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, ScheduledTask $task, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(ScheduledTaskFormType::class, $task);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', '"' . $task->getName() . '" schedule updated.');
            return $this->redirectToRoute('scheduler_index');
        }

        return $this->render('scheduled_task/edit.html.twig', [
            'task' => $task,
            'form' => $form,
        ]);
    }

    #[Route('/scheduler/{id}/run', name: 'scheduler_run', methods: ['POST'])]
    public function run(
        Request $request,
        ScheduledTask $task,
        MessageBusInterface $bus,
    ): Response {
        if (!$this->isCsrfTokenValid('run_task_' . $task->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('scheduler_index');
        }

        $bus->dispatch(new RunScheduledTaskMessage($task->getId()));

        $this->addFlash('info', '"' . $task->getName() . '" is running in the background. Check the output page in a moment to see the result.');
        return $this->redirectToRoute('scheduler_index');
    }

    #[Route('/scheduler/{id}/output', name: 'scheduler_output', methods: ['GET'])]
    public function output(ScheduledTask $task): Response
    {
        return $this->render('scheduled_task/output.html.twig', ['task' => $task]);
    }
}
