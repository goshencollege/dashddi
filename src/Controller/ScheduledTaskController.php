<?php

namespace App\Controller;

use App\Entity\ScheduledTask;
use App\Form\ScheduledTaskFormType;
use App\Repository\ScheduledTaskRepository;
use App\Service\ScheduledTaskRunnerService;
use Cron\CronExpression;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ScheduledTaskController extends AbstractController
{
    #[Route('/scheduler', name: 'scheduler_index', methods: ['GET'])]
    public function index(ScheduledTaskRepository $repo): Response
    {
        $tasks   = $repo->findBy([], ['id' => 'ASC']);
        $nextRun = [];

        foreach ($tasks as $task) {
            try {
                $cron            = new CronExpression($task->getCronExpression());
                $nextRun[$task->getId()] = $cron->getNextRunDate();
            } catch (\Exception) {
                $nextRun[$task->getId()] = null;
            }
        }

        return $this->render('scheduled_task/index.html.twig', [
            'tasks'   => $tasks,
            'nextRun' => $nextRun,
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
        ScheduledTaskRunnerService $runner,
    ): Response {
        if (!$this->isCsrfTokenValid('run_task_' . $task->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('scheduler_index');
        }

        $runner->run($task);

        $status = $task->getLastRunStatus();
        if ($status === 'success') {
            $this->addFlash('success', '"' . $task->getName() . '" completed successfully.');
        } else {
            $this->addFlash('danger', '"' . $task->getName() . '" failed. Check the output for details.');
        }

        return $this->redirectToRoute('scheduler_output', ['id' => $task->getId()]);
    }

    #[Route('/scheduler/{id}/output', name: 'scheduler_output', methods: ['GET'])]
    public function output(ScheduledTask $task): Response
    {
        return $this->render('scheduled_task/output.html.twig', ['task' => $task]);
    }
}
