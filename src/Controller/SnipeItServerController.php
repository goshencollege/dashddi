<?php

namespace App\Controller;

use App\Entity\SnipeItCategorySubnetMap;
use App\Entity\SnipeItServer;
use App\Form\SnipeItCategorySubnetAssignType;
use App\Form\SnipeItServerType;
use App\Message\PullSnipeItMessage;
use App\Repository\SnipeItCategorySubnetMapRepository;
use App\Repository\SnipeItServerRepository;
use App\Service\SnipeItSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
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

        $subnetForms = [];
        foreach ($server->getCategorySubnetMaps() as $mapping) {
            $subnetForms[$mapping->getId()] = $this->createForm(SnipeItCategorySubnetAssignType::class, $mapping, [
                'action' => $this->generateUrl('snipe_it_category_map_update', [
                    'server' => $server->getId(),
                    'map'    => $mapping->getId(),
                ]),
            ])->createView();
        }

        return $this->render('snipe_it_server/form.html.twig', [
            'form'        => $form,
            'server'      => $server,
            'title'       => 'Edit: ' . $server->getName(),
            'subnetForms' => $subnetForms,
        ]);
    }

    #[Route('/{id}/fetch-categories', name: 'snipe_it_fetch_categories', methods: ['POST'])]
    public function fetchCategories(
        Request $request,
        SnipeItServer $server,
        SnipeItSyncService $syncService,
        SnipeItCategorySubnetMapRepository $mapRepo,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('fetch_categories_' . $server->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('snipe_it_server_edit', ['id' => $server->getId()]);
        }

        try {
            $categories = $syncService->fetchCategories($server);
        } catch (\RuntimeException $e) {
            $this->addFlash('error', 'Could not fetch categories: ' . $e->getMessage());
            return $this->redirectToRoute('snipe_it_server_edit', ['id' => $server->getId()]);
        }

        $existing = [];
        foreach ($mapRepo->findBy(['server' => $server]) as $map) {
            $existing[$map->getSnipeCategoryId()] = $map;
        }

        $added = $updated = 0;
        foreach ($categories as $cat) {
            if (isset($existing[$cat['id']])) {
                if ($existing[$cat['id']]->getSnipeCategoryName() !== $cat['name']) {
                    $existing[$cat['id']]->setSnipeCategoryName($cat['name']);
                    $updated++;
                }
            } else {
                $map = new SnipeItCategorySubnetMap();
                $map->setServer($server);
                $map->setSnipeCategoryId($cat['id']);
                $map->setSnipeCategoryName($cat['name']);
                $em->persist($map);
                $added++;
            }
        }

        $em->flush();

        $parts = [];
        if ($added)   { $parts[] = $added . ' added'; }
        if ($updated) { $parts[] = $updated . ' updated'; }
        $this->addFlash('success', 'Categories fetched' . ($parts ? ': ' . implode(', ', $parts) . '.' : ' — no changes.'));

        return $this->redirectToRoute('snipe_it_server_edit', ['id' => $server->getId()]);
    }

    #[Route('/{server}/category-maps/{map}/update', name: 'snipe_it_category_map_update', methods: ['POST'])]
    public function updateCategoryMap(Request $request, SnipeItServer $server, SnipeItCategorySubnetMap $map, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(SnipeItCategorySubnetAssignType::class, $map);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }
        }

        return $this->redirectToRoute('snipe_it_server_edit', ['id' => $server->getId()]);
    }

    #[Route('/{server}/category-maps/{map}/delete', name: 'snipe_it_category_map_delete', methods: ['POST'])]
    public function deleteCategoryMap(Request $request, SnipeItServer $server, SnipeItCategorySubnetMap $map, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_category_map_' . $map->getId(), $request->request->get('_token'))) {
            $em->remove($map);
            $em->flush();
        }

        return $this->redirectToRoute('snipe_it_server_edit', ['id' => $server->getId()]);
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
    public function pull(SnipeItServerRepository $repo, MessageBusInterface $bus): JsonResponse
    {
        $servers = $repo->findBy([], ['name' => 'ASC']);

        if (empty($servers)) {
            return $this->json(['error' => 'No Snipe-IT servers configured.'], 400);
        }

        foreach ($servers as $server) {
            $bus->dispatch(
                new PullSnipeItMessage($server->getId()),
                [new DeduplicateStamp('pull_snipe_it_' . $server->getId(), ttl: 3600)],
            );
        }

        return $this->json(['queued' => true, 'count' => count($servers)], 202);
    }
}
