<?php

namespace App\Controller;

use App\Entity\DhcpServer;
use App\Form\DhcpServerType;
use App\Repository\DhcpServerRepository;
use App\Service\KeaDeployService;
use App\Service\SshKeyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dhcp-servers')]
class DhcpServerController extends AbstractController
{
    public function __construct(private readonly SshKeyService $sshKeys) {}

    #[Route('', name: 'dhcp_server_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('servers_index');
    }

    #[Route('/new', name: 'dhcp_server_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $server = new DhcpServer();
        $form   = $this->createForm(DhcpServerType::class, $server);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $keys = $this->sshKeys->generateKeyPair();
            $server->setSshPrivateKey($keys['private'])->setSshPublicKey($keys['public']);
            $em->persist($server);
            $em->flush();
            $this->addFlash('success', 'DHCP server "' . $server->getName() . '" added. Add the public key to authorized_keys on the server.');
            return $this->redirectToRoute('dhcp_server_edit', ['id' => $server->getId()]);
        }

        return $this->render('dhcp_server/form.html.twig', [
            'form'   => $form,
            'server' => $server,
            'title'  => 'Add DHCP Server',
        ]);
    }

    #[Route('/{id}/edit', name: 'dhcp_server_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, DhcpServer $server, EntityManagerInterface $em): Response
    {
        $existingPassword = $server->getControlPassword();
        $form = $this->createForm(DhcpServerType::class, $server);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($server->getControlPassword() === null) {
                $server->setControlPassword($existingPassword);
            }
            $em->flush();
            $this->addFlash('success', 'DHCP server updated.');
            return $this->redirectToRoute('dhcp_server_edit', ['id' => $server->getId()]);
        }

        return $this->render('dhcp_server/form.html.twig', [
            'form'   => $form,
            'server' => $server,
            'title'  => 'Edit: ' . $server->getName(),
        ]);
    }

    #[Route('/{id}/regenerate-key', name: 'dhcp_server_regenerate_key', methods: ['POST'])]
    public function regenerateKey(Request $request, DhcpServer $server, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('regen_key_dhcp_' . $server->getId(), $request->request->get('_token'))) {
            $keys = $this->sshKeys->generateKeyPair();
            $server->setSshPrivateKey($keys['private'])->setSshPublicKey($keys['public']);
            $em->flush();
            $this->addFlash('warning', 'SSH key regenerated. Update authorized_keys on "' . $server->getName() . '" with the new public key.');
        }

        return $this->redirectToRoute('dhcp_server_edit', ['id' => $server->getId()]);
    }

    #[Route('/{id}/delete', name: 'dhcp_server_delete', methods: ['POST'])]
    public function delete(Request $request, DhcpServer $server, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_dhcp_server_' . $server->getId(), $request->request->get('_token'))) {
            $em->remove($server);
            $em->flush();
            $this->addFlash('success', 'DHCP server deleted.');
        }
        return $this->redirectToRoute('dhcp_server_index');
    }

    #[Route('/push', name: 'dhcp_server_push', methods: ['POST'])]
    public function push(DhcpServerRepository $repo, KeaDeployService $deployer): JsonResponse
    {
        $servers = $repo->findBy([], ['name' => 'ASC']);

        if (empty($servers)) {
            return $this->json(['error' => 'No DHCP servers configured.'], 400);
        }

        $results = [];
        foreach ($servers as $server) {
            try {
                $results[$server->getName()] = $deployer->deployToServer($server);
            } catch (\Throwable $e) {
                $results[$server->getName()] = [
                    'dhcp4' => ['success' => false, 'output' => $e->getMessage(), 'file' => 'subnets4.json'],
                    'dhcp6' => ['success' => false, 'output' => $e->getMessage(), 'file' => 'subnets6.json'],
                ];
            }
        }

        return $this->json($results);
    }
}
