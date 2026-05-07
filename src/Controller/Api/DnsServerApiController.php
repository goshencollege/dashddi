<?php

namespace App\Controller\Api;

use App\Entity\DnsServer;
use App\Repository\DnsServerRepository;
use App\Repository\DnsViewRepository;
use App\Service\DnsDeployService;
use App\Service\SshKeyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/dns-servers')]
class DnsServerApiController extends AbstractController
{
    #[Route('', name: 'api_dns_servers_index', methods: ['GET'])]
    public function index(DnsServerRepository $repo): JsonResponse
    {
        $servers = $repo->findBy([], ['name' => 'ASC']);

        return $this->json(array_map($this->serialize(...), $servers));
    }

    #[Route('/{id}', name: 'api_dns_servers_show', methods: ['GET'])]
    public function show(DnsServer $server): JsonResponse
    {
        return $this->json($this->serialize($server));
    }

    #[Route('', name: 'api_dns_servers_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        DnsViewRepository $viewRepo,
        SshKeyService $sshKeys,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        if (empty($data['name'])) {
            return $this->json(['error' => 'name is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (empty($data['hostname'])) {
            return $this->json(['error' => 'hostname is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $serverType = $data['server_type'] ?? 'primary';
        if (!in_array($serverType, ['primary', 'secondary'], true)) {
            return $this->json(['error' => 'server_type must be primary or secondary'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $server = new DnsServer();
        $server->setName($data['name']);
        $server->setHostname($data['hostname']);
        $server->setSshUser($data['ssh_user'] ?? 'root');
        $server->setRemoteZonePath($data['remote_zone_path'] ?? '/etc/bind/zones');
        $server->setKeyDirectory($data['key_directory'] ?? null);
        $server->setServerType($serverType);
        $server->setPrimaryHostname($data['primary_hostname'] ?? null);
        $server->setDescription($data['description'] ?? null);

        foreach ($data['view_ids'] ?? [] as $viewId) {
            $view = $viewRepo->find($viewId);
            if ($view) {
                $server->addView($view);
            }
        }

        if ($serverType === 'secondary' && $server->getViews()->count() > 1) {
            return $this->json(['error' => 'A secondary server can only be assigned one view.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $keys = $sshKeys->generateKeyPair();
        $server->setSshPrivateKey($keys['private'])->setSshPublicKey($keys['public']);

        $em->persist($server);
        $em->flush();

        return $this->json($this->serialize($server), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_dns_servers_update', methods: ['PATCH'])]
    public function update(
        Request $request,
        DnsServer $server,
        EntityManagerInterface $em,
        DnsViewRepository $viewRepo,
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
        if (array_key_exists('ssh_user', $data))         { $server->setSshUser($data['ssh_user']); }
        if (array_key_exists('remote_zone_path', $data)) { $server->setRemoteZonePath($data['remote_zone_path']); }
        if (array_key_exists('key_directory', $data))    { $server->setKeyDirectory($data['key_directory']); }
        if (array_key_exists('primary_hostname', $data)) { $server->setPrimaryHostname($data['primary_hostname']); }
        if (array_key_exists('description', $data))      { $server->setDescription($data['description']); }

        if (array_key_exists('server_type', $data)) {
            if (!in_array($data['server_type'], ['primary', 'secondary'], true)) {
                return $this->json(['error' => 'server_type must be primary or secondary'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $server->setServerType($data['server_type']);
        }

        if (array_key_exists('view_ids', $data)) {
            foreach ($server->getViews()->toArray() as $view) {
                $server->removeView($view);
            }
            foreach ($data['view_ids'] as $viewId) {
                $view = $viewRepo->find($viewId);
                if ($view) {
                    $server->addView($view);
                }
            }
        }

        if ($server->isSecondary() && $server->getViews()->count() > 1) {
            return $this->json(['error' => 'A secondary server can only be assigned one view.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em->flush();

        return $this->json($this->serialize($server));
    }

    #[Route('/{id}', name: 'api_dns_servers_delete', methods: ['DELETE'])]
    public function delete(DnsServer $server, EntityManagerInterface $em): JsonResponse
    {
        $em->remove($server);
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/push', name: 'api_dns_servers_push', methods: ['POST'])]
    public function push(DnsServer $server, DnsDeployService $deployer): JsonResponse
    {
        try {
            $result = $deployer->deployToServer($server);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json($result);
    }

    private function serialize(DnsServer $server): array
    {
        return [
            'id'               => $server->getId(),
            'name'             => $server->getName(),
            'hostname'         => $server->getHostname(),
            'ssh_user'         => $server->getSshUser(),
            'ssh_public_key'   => $server->getSshPublicKey(),
            'remote_zone_path' => $server->getRemoteZonePath(),
            'key_directory'    => $server->getKeyDirectory(),
            'server_type'      => $server->getServerType(),
            'primary_hostname' => $server->getPrimaryHostname(),
            'description'      => $server->getDescription(),
            'view_ids'         => $server->getViews()->map(fn($v) => $v->getId())->toArray(),
            'created_at'       => $server->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at'       => $server->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'created_by'       => $server->getCreatedBy(),
            'updated_by'       => $server->getUpdatedBy(),
        ];
    }
}
