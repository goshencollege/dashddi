<?php

namespace App\Controller;

use App\Entity\ClearpassServer;
use App\Form\ClearpassServerType;
use App\Message\PullClearpassLogsMessage;
use App\Message\PushClearpassAllMessage;
use App\Message\PushClearpassMessage;
use App\Repository\ClearpassServerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/clearpass-servers')]
class ClearpassServerController extends AbstractController
{
    #[Route('', name: 'clearpass_server_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('servers_index');
    }

    #[Route('/new', name: 'clearpass_server_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $server = new ClearpassServer();
        $form   = $this->createForm(ClearpassServerType::class, $server);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$server->getClientSecret()) {
                $this->addFlash('error', 'OAuth client secret is required.');
                return $this->render('clearpass_server/form.html.twig', [
                    'form'   => $form,
                    'server' => $server,
                    'title'  => 'Add ClearPass Server',
                ]);
            }
            $em->persist($server);
            $em->flush();
            $this->addFlash('success', 'ClearPass server "' . $server->getName() . '" added.');
            return $this->redirectToRoute('servers_index');
        }

        return $this->render('clearpass_server/form.html.twig', [
            'form'   => $form,
            'server' => $server,
            'title'  => 'Add ClearPass Server',
        ]);
    }

    #[Route('/{id}/edit', name: 'clearpass_server_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, ClearpassServer $server, EntityManagerInterface $em): Response
    {
        $existingSecret = $server->getClientSecret();
        $form = $this->createForm(ClearpassServerType::class, $server);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($server->getClientSecret() === '' || $server->getClientSecret() === null) {
                $server->setClientSecret($existingSecret);
            }
            $em->flush();
            $this->addFlash('success', 'ClearPass server updated.');
            return $this->redirectToRoute('clearpass_server_edit', ['id' => $server->getId()]);
        }

        return $this->render('clearpass_server/form.html.twig', [
            'form'   => $form,
            'server' => $server,
            'title'  => 'Edit: ' . $server->getName(),
        ]);
    }

    #[Route('/{id}/delete', name: 'clearpass_server_delete', methods: ['POST'])]
    public function delete(Request $request, ClearpassServer $server, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_clearpass_server_' . $server->getId(), $request->request->get('_token'))) {
            $em->remove($server);
            $em->flush();
            $this->addFlash('success', 'ClearPass server deleted.');
        }
        return $this->redirectToRoute('clearpass_server_index');
    }

    #[Route('/pull-logs', name: 'clearpass_server_pull_logs', methods: ['POST'])]
    public function pullLogs(Request $request, ClearpassServerRepository $repo, MessageBusInterface $bus): JsonResponse
    {
        if (!$this->isCsrfTokenValid('clearpass_pull_logs', $request->headers->get('X-CSRF-Token') ?? $request->request->get('_token'))) {
            return $this->json(['error' => 'Invalid CSRF token.'], 403);
        }

        $servers = $repo->findBy([], ['name' => 'ASC']);

        if (empty($servers)) {
            return $this->json(['error' => 'No ClearPass servers configured.'], 400);
        }

        $bus->dispatch(new PullClearpassLogsMessage(), [new DeduplicateStamp('pull_clearpass_logs', ttl: 3600)]);

        return $this->json(['queued' => true, 'count' => count($servers)], 202);
    }

    #[Route('/push', name: 'clearpass_server_push', methods: ['POST'])]
    public function push(Request $request, ClearpassServerRepository $repo, MessageBusInterface $bus): JsonResponse
    {
        if (!$this->isCsrfTokenValid('clearpass_push', $request->headers->get('X-CSRF-Token') ?? $request->request->get('_token'))) {
            return $this->json(['error' => 'Invalid CSRF token.'], 403);
        }

        $servers = $repo->findBy([], ['name' => 'ASC']);

        if (empty($servers)) {
            return $this->json(['error' => 'No ClearPass servers configured.'], 400);
        }

        foreach ($servers as $server) {
            $bus->dispatch(new PushClearpassAllMessage($server->getId()), [new DeduplicateStamp('push_clearpass_' . $server->getId() . '_all', ttl: 3600)]);
        }

        return $this->json(['queued' => true, 'count' => count($servers)], 202);
    }
}
