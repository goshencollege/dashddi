<?php

namespace App\Controller;

use App\Entity\SnipeItServer;
use App\Form\SnipeItServerType;
use App\Repository\SnipeItServerRepository;
use App\Service\SnipeItSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/snipe-it-servers')]
class SnipeItServerController extends AbstractController
{
    #[Route('', name: 'snipe_it_server_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('servers_index');
    }

    #[Route('/new', name: 'snipe_it_server_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $server = new SnipeItServer();
        $form   = $this->createForm(SnipeItServerType::class, $server);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$server->getApiKey()) {
                $this->addFlash('error', 'API key is required.');
                return $this->render('snipe_it_server/form.html.twig', [
                    'form'   => $form,
                    'server' => $server,
                    'title'  => 'Add Snipe-IT Server',
                ]);
            }
            $em->persist($server);
            $em->flush();
            $this->addFlash('success', 'Snipe-IT server "' . $server->getName() . '" added.');
            return $this->redirectToRoute('servers_index');
        }

        return $this->render('snipe_it_server/form.html.twig', [
            'form'   => $form,
            'server' => $server,
            'title'  => 'Add Snipe-IT Server',
        ]);
    }

    #[Route('/{id}/edit', name: 'snipe_it_server_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, SnipeItServer $server, EntityManagerInterface $em): Response
    {
        $existingKey = $server->getApiKey();
        $form = $this->createForm(SnipeItServerType::class, $server);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($server->getApiKey() === '' || $server->getApiKey() === null) {
                $server->setApiKey($existingKey);
            }
            $em->flush();
            $this->addFlash('success', 'Snipe-IT server updated.');
            return $this->redirectToRoute('snipe_it_server_edit', ['id' => $server->getId()]);
        }

        return $this->render('snipe_it_server/form.html.twig', [
            'form'   => $form,
            'server' => $server,
            'title'  => 'Edit: ' . $server->getName(),
        ]);
    }

    #[Route('/{id}/delete', name: 'snipe_it_server_delete', methods: ['POST'])]
    public function delete(Request $request, SnipeItServer $server, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_snipe_it_server_' . $server->getId(), $request->request->get('_token'))) {
            $em->remove($server);
            $em->flush();
            $this->addFlash('success', 'Snipe-IT server deleted. All associated hosts have been removed.');
        }
        return $this->redirectToRoute('snipe_it_server_index');
    }

    #[Route('/pull', name: 'snipe_it_server_pull', methods: ['POST'])]
    public function pull(SnipeItServerRepository $repo, SnipeItSyncService $syncService): JsonResponse
    {
        $servers = $repo->findBy([], ['name' => 'ASC']);

        if (empty($servers)) {
            return $this->json(['error' => 'No Snipe-IT servers configured.'], 400);
        }

        $totals = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0];
        $errors = [];

        foreach ($servers as $server) {
            try {
                $result = $syncService->syncFromServer($server);
                foreach ($totals as $k => $_) {
                    $totals[$k] += $result[$k];
                }
                foreach ($result['errors'] as $e) {
                    $errors[] = $server->getName() . ': ' . $e;
                }
            } catch (\Throwable $e) {
                $errors[] = $server->getName() . ': ' . $e->getMessage();
            }
        }

        return $this->json([
            'count'   => count($servers),
            'totals'  => $totals,
            'errors'  => $errors,
        ], 200);
    }
}
