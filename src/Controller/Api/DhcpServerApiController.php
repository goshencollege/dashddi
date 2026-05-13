<?php

namespace App\Controller\Api;

use App\Entity\DhcpServer;
use App\Message\PushDhcpMessage;
use App\Repository\DhcpServerRepository;
use App\Service\SshKeyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/dhcp-servers')]
class DhcpServerApiController extends AbstractController
{
    #[Route('', name: 'api_dhcp_servers_index', methods: ['GET'])]
    public function index(DhcpServerRepository $repo): JsonResponse
    {
        $servers = $repo->findBy([], ['name' => 'ASC']);

        return $this->json(array_map($this->serialize(...), $servers));
    }

    #[Route('/{id}', name: 'api_dhcp_servers_show', methods: ['GET'])]
    public function show(DhcpServer $server): JsonResponse
    {
        return $this->json($this->serialize($server));
    }

    #[Route('', name: 'api_dhcp_servers_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        SshKeyService $sshKeys,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        if (empty($data['name'])) {
            return $this->json(['error' => 'name is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (empty($data['hostname'])) {
            return $this->json(['error' => 'hostname is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $server = new DhcpServer();
        $server->setName($data['name']);
        $server->setHostname($data['hostname']);
        $server->setSshUser($data['ssh_user'] ?? 'root');
        $server->setRemotePath($data['remote_path'] ?? '/etc/kea');
        $server->setControlUrl($data['control_url'] ?? null);
        $server->setControlUser($data['control_user'] ?? null);
        $server->setControlPassword($data['control_password'] ?? null);
        $server->setDescription($data['description'] ?? null);

        $keys = $sshKeys->generateKeyPair();
        $server->setSshPrivateKey($keys['private'])->setSshPublicKey($keys['public']);

        $em->persist($server);
        $em->flush();

        return $this->json($this->serialize($server), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_dhcp_servers_update', methods: ['PATCH'])]
    public function update(
        Request $request,
        DhcpServer $server,
        EntityManagerInterface $em,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('name', $data)) {
            if (empty($data['name'])) {
                return $this->json(['error' => 'name cannot be empty'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $server->setName($data['name']);
        }
        if (array_key_exists('hostname', $data)) {
            if (empty($data['hostname'])) {
                return $this->json(['error' => 'hostname cannot be empty'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $server->setHostname($data['hostname']);
        }
        if (array_key_exists('ssh_user', $data))        { $server->setSshUser($data['ssh_user']); }
        if (array_key_exists('remote_path', $data))     { $server->setRemotePath($data['remote_path']); }
        if (array_key_exists('control_url', $data))     { $server->setControlUrl($data['control_url']); }
        if (array_key_exists('control_user', $data))    { $server->setControlUser($data['control_user']); }
        if (array_key_exists('control_password', $data)) { $server->setControlPassword($data['control_password']); }
        if (array_key_exists('description', $data))     { $server->setDescription($data['description']); }

        $em->flush();

        return $this->json($this->serialize($server));
    }

    #[Route('/{id}', name: 'api_dhcp_servers_delete', methods: ['DELETE'])]
    public function delete(DhcpServer $server, EntityManagerInterface $em): JsonResponse
    {
        $em->remove($server);
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/push', name: 'api_dhcp_servers_push', methods: ['POST'])]
    public function push(DhcpServer $server, MessageBusInterface $bus): JsonResponse
    {
        $bus->dispatch(new PushDhcpMessage($server->getId()));

        return $this->json(['queued' => true], Response::HTTP_ACCEPTED);
    }

    private function serialize(DhcpServer $server): array
    {
        return [
            'id'           => $server->getId(),
            'name'         => $server->getName(),
            'hostname'     => $server->getHostname(),
            'ssh_user'     => $server->getSshUser(),
            'ssh_public_key' => $server->getSshPublicKey(),
            'remote_path'  => $server->getRemotePath(),
            'control_url'  => $server->getControlUrl(),
            'control_user' => $server->getControlUser(),
            'description'  => $server->getDescription(),
            'created_at'   => $server->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at'   => $server->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'created_by'   => $server->getCreatedBy(),
            'updated_by'   => $server->getUpdatedBy(),
        ];
    }
}
