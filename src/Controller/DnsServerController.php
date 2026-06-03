<?php

namespace App\Controller;

use App\Entity\DnsServer;
use App\Form\DnsServerType;
use App\Message\PushDnsMessage;
use App\Repository\DnsServerRepository;
use App\Service\SshKeyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dns-servers')]
class DnsServerController extends AbstractController
{
    public function __construct(private readonly SshKeyService $sshKeys) {}

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
            $keys = $this->sshKeys->generateKeyPair();
            $server->setSshPrivateKey($keys['private'])->setSshPublicKey($keys['public']);
            $em->persist($server);
            $em->flush();
            $this->addFlash('success', 'DNS server "' . $server->getName() . '" added. Add the SSH public key below to authorized_keys on the server.');
            $this->addFlash('ssh_pubkey', $server->getSshPublicKey());
            return $this->redirectToRoute('servers_index');
        }

        return $this->render('dns_server/form.html.twig', [
            'form'   => $form,
            'server' => $server,
            'title'  => 'Add DNS Server',
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
            return $this->redirectToRoute('dns_server_edit', ['id' => $server->getId()]);
        }

        return $this->render('dns_server/form.html.twig', [
            'form'   => $form,
            'server' => $server,
            'title'  => 'Edit: ' . $server->getName(),
        ]);
    }

    #[Route('/{id}/regenerate-key', name: 'dns_server_regenerate_key', methods: ['POST'])]
    public function regenerateKey(Request $request, DnsServer $server, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('regen_key_dns_' . $server->getId(), $request->request->get('_token'))) {
            $keys = $this->sshKeys->generateKeyPair();
            $server->setSshPrivateKey($keys['private'])->setSshPublicKey($keys['public']);
            $em->flush();
            $this->addFlash('warning', 'SSH key regenerated. Update authorized_keys on "' . $server->getName() . '" with the new public key.');
        }

        return $this->redirectToRoute('dns_server_edit', ['id' => $server->getId()]);
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

    #[Route('/{id}/test-ssh', name: 'dns_server_test_ssh', methods: ['POST'])]
    public function testSsh(Request $request, DnsServer $server): JsonResponse
    {
        if (!$this->isCsrfTokenValid('test_ssh_dns_' . $server->getId(), $request->request->get('_token'))) {
            return $this->json(['error' => 'Invalid CSRF token.'], 403);
        }

        if (!$server->getSshPrivateKey()) {
            return $this->json(['error' => 'No SSH key configured for this server.'], 400);
        }

        $result = $this->sshKeys->testConnection(
            $server->getHostname(),
            $server->getSshUser(),
            $server->getSshPrivateKey(),
        );

        return $this->json($result);
    }

    #[Route('/push', name: 'dns_server_push', methods: ['POST'])]
    public function push(DnsServerRepository $repo, MessageBusInterface $bus): JsonResponse
    {
        $servers = $repo->findBy([], ['name' => 'ASC']);

        if (empty($servers)) {
            return $this->json(['error' => 'No DNS servers configured.'], 400);
        }

        foreach ($servers as $server) {
            $bus->dispatch(new PushDnsMessage($server->getId()), [new DeduplicateStamp('push_dns_' . $server->getId())]);
        }

        return $this->json(['queued' => true, 'count' => count($servers)], 202);
    }
}
