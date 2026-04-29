<?php

namespace App\Controller;

use App\Entity\DnsServer;
use App\Form\DnsServerType;
use App\Repository\DnsServerRepository;
use App\Service\DnsDeployService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dns-servers')]
class DnsServerController extends AbstractController
{
    #[Route('', name: 'dns_server_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('servers_index');
    }

    #[Route('/new', name: 'dns_server_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $server = new DnsServer();
        $form   = $this->createForm(DnsServerType::class, $server);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($server);
            $em->flush();
            $this->addFlash('success', 'DNS server "' . $server->getName() . '" added.');
            return $this->redirectToRoute('dns_server_index');
        }

        return $this->render('dns_server/form.html.twig', [
            'form'  => $form,
            'title' => 'Add DNS Server',
        ]);
    }

    #[Route('/{id}/edit', name: 'dns_server_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, DnsServer $server, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(DnsServerType::class, $server);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'DNS server updated.');
            return $this->redirectToRoute('dns_server_index');
        }

        return $this->render('dns_server/form.html.twig', [
            'form'  => $form,
            'title' => 'Edit: ' . $server->getName(),
        ]);
    }

    #[Route('/{id}/delete', name: 'dns_server_delete', methods: ['POST'])]
    public function delete(Request $request, DnsServer $server, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_dns_server_' . $server->getId(), $request->request->get('_token'))) {
            $em->remove($server);
            $em->flush();
            $this->addFlash('success', 'DNS server deleted.');
        }
        return $this->redirectToRoute('dns_server_index');
    }

    #[Route('/push', name: 'dns_server_push', methods: ['POST'])]
    public function push(DnsServerRepository $repo, DnsDeployService $deployer): JsonResponse
    {
        $servers = $repo->findBy([], ['name' => 'ASC']);

        if (empty($servers)) {
            return $this->json(['error' => 'No DNS servers configured.'], 400);
        }

        $results = [];
        foreach ($servers as $server) {
            try {
                $results[$server->getName()] = $deployer->deployToServer($server);
            } catch (\Throwable $e) {
                $results[$server->getName()] = [
                    'zones'  => [],
                    'reload' => ['success' => false, 'output' => $e->getMessage()],
                ];
            }
        }

        return $this->json($results);
    }
}
