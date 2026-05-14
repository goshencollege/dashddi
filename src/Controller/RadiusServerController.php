<?php

namespace App\Controller;

use App\Entity\RadiusServer;
use App\Form\RadiusServerType;
use App\Message\PushRadiusMessage;
use App\Repository\RadiusServerRepository;
use App\Service\SshKeyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/radius-servers')]
class RadiusServerController extends AbstractController
{
    public function __construct(private readonly SshKeyService $sshKeys) {}

    #[Route('', name: 'radius_server_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('servers_index');
    }

    #[Route('/new', name: 'radius_server_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $server = new RadiusServer();
        $form   = $this->createForm(RadiusServerType::class, $server);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $keys = $this->sshKeys->generateKeyPair();
            $server->setSshPrivateKey($keys['private'])->setSshPublicKey($keys['public']);
            $em->persist($server);
            $em->flush();
            $this->addFlash('success', 'RADIUS server "' . $server->getName() . '" added. Add the SSH public key below to authorized_keys on the server.');
            $this->addFlash('ssh_pubkey', $server->getSshPublicKey());
            return $this->redirectToRoute('servers_index');
        }

        return $this->render('radius_server/form.html.twig', [
            'form'   => $form,
            'server' => $server,
            'title'  => 'Add RADIUS Server',
        ]);
    }

    #[Route('/{id}/edit', name: 'radius_server_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, RadiusServer $server, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(RadiusServerType::class, $server);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'RADIUS server updated.');
            return $this->redirectToRoute('radius_server_edit', ['id' => $server->getId()]);
        }

        return $this->render('radius_server/form.html.twig', [
            'form'   => $form,
            'server' => $server,
            'title'  => 'Edit: ' . $server->getName(),
        ]);
    }

    #[Route('/{id}/regenerate-key', name: 'radius_server_regenerate_key', methods: ['POST'])]
    public function regenerateKey(Request $request, RadiusServer $server, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('regen_key_radius_' . $server->getId(), $request->request->get('_token'))) {
            $keys = $this->sshKeys->generateKeyPair();
            $server->setSshPrivateKey($keys['private'])->setSshPublicKey($keys['public']);
            $em->flush();
            $this->addFlash('warning', 'SSH key regenerated. Update authorized_keys on "' . $server->getName() . '" with the new public key.');
        }

        return $this->redirectToRoute('radius_server_edit', ['id' => $server->getId()]);
    }

    #[Route('/{id}/delete', name: 'radius_server_delete', methods: ['POST'])]
    public function delete(Request $request, RadiusServer $server, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_radius_server_' . $server->getId(), $request->request->get('_token'))) {
            $em->remove($server);
            $em->flush();
            $this->addFlash('success', 'RADIUS server deleted.');
        }
        return $this->redirectToRoute('radius_server_index');
    }

    #[Route('/push', name: 'radius_server_push', methods: ['POST'])]
    public function push(RadiusServerRepository $repo, MessageBusInterface $bus): JsonResponse
    {
        $servers = $repo->findBy([], ['name' => 'ASC']);

        if (empty($servers)) {
            return $this->json(['error' => 'No RADIUS servers configured.'], 400);
        }

        foreach ($servers as $server) {
            $bus->dispatch(new PushRadiusMessage($server->getId()));
        }

        return $this->json(['queued' => true, 'count' => count($servers)], 202);
    }
}
