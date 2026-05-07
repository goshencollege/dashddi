<?php

namespace App\Controller\Api;

use App\Entity\InterfaceName;
use App\Repository\DnsViewRepository;
use App\Repository\DomainRepository;
use App\Repository\InterfaceNameRepository;
use App\Repository\NetworkInterfaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/interface-names')]
class InterfaceNameApiController extends AbstractController
{
    #[Route('', name: 'api_interface_names_index', methods: ['GET'])]
    public function index(Request $request, InterfaceNameRepository $repo): JsonResponse
    {
        $qb = $repo->createQueryBuilder('n');

        if ($interfaceId = $request->query->getInt('interface_id')) {
            $qb->andWhere('n.networkInterface = :iid')->setParameter('iid', $interfaceId);
        }
        if ($domainId = $request->query->getInt('domain_id')) {
            $qb->andWhere('n.domain = :did')->setParameter('did', $domainId);
        }

        $names = $qb->orderBy('n.name', 'ASC')->getQuery()->getResult();

        return $this->json(array_map($this->serialize(...), $names));
    }

    #[Route('/{id}', name: 'api_interface_names_show', methods: ['GET'])]
    public function show(InterfaceName $interfaceName): JsonResponse
    {
        return $this->json($this->serialize($interfaceName));
    }

    #[Route('', name: 'api_interface_names_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        NetworkInterfaceRepository $interfaceRepo,
        DomainRepository $domainRepo,
        DnsViewRepository $viewRepo,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        if (empty($data['interface_id'])) {
            return $this->json(['error' => 'interface_id is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (empty($data['name'])) {
            return $this->json(['error' => 'name is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $interface = $interfaceRepo->find($data['interface_id']);
        if (!$interface) {
            return $this->json(['error' => 'interface_id not found'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $ifaceName = new InterfaceName();
        $ifaceName->setNetworkInterface($interface);
        $ifaceName->setName($data['name']);
        $ifaceName->setTtl(isset($data['ttl']) ? (int) $data['ttl'] : null);
        $ifaceName->setIsCanonical((bool) ($data['is_canonical'] ?? false));

        if (!empty($data['domain_id'])) {
            $domain = $domainRepo->find($data['domain_id']);
            if (!$domain) {
                return $this->json(['error' => 'domain_id not found'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $ifaceName->setDomain($domain);
        }

        foreach ($data['view_ids'] ?? [] as $viewId) {
            $view = $viewRepo->find($viewId);
            if ($view) {
                $ifaceName->addView($view);
            }
        }

        $em->persist($ifaceName);
        $em->flush();

        return $this->json($this->serialize($ifaceName), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_interface_names_update', methods: ['PATCH'])]
    public function update(
        Request $request,
        InterfaceName $interfaceName,
        EntityManagerInterface $em,
        DomainRepository $domainRepo,
        DnsViewRepository $viewRepo,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('name', $data)) {
            if (empty($data['name'])) {
                return $this->json(['error' => 'name cannot be empty'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $interfaceName->setName($data['name']);
        }

        if (array_key_exists('ttl', $data)) {
            $interfaceName->setTtl($data['ttl'] !== null ? (int) $data['ttl'] : null);
        }

        if (array_key_exists('is_canonical', $data)) {
            $interfaceName->setIsCanonical((bool) $data['is_canonical']);
        }

        if (array_key_exists('domain_id', $data)) {
            if ($data['domain_id'] === null) {
                $interfaceName->setDomain(null);
            } else {
                $domain = $domainRepo->find($data['domain_id']);
                if (!$domain) {
                    return $this->json(['error' => 'domain_id not found'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $interfaceName->setDomain($domain);
            }
        }

        if (array_key_exists('view_ids', $data)) {
            foreach ($interfaceName->getViews()->toArray() as $view) {
                $interfaceName->removeView($view);
            }
            foreach ($data['view_ids'] as $viewId) {
                $view = $viewRepo->find($viewId);
                if ($view) {
                    $interfaceName->addView($view);
                }
            }
        }

        $em->flush();

        return $this->json($this->serialize($interfaceName));
    }

    #[Route('/{id}', name: 'api_interface_names_delete', methods: ['DELETE'])]
    public function delete(InterfaceName $interfaceName, EntityManagerInterface $em): JsonResponse
    {
        $em->remove($interfaceName);
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function serialize(InterfaceName $name): array
    {
        return [
            'id'           => $name->getId(),
            'name'         => $name->getName(),
            'fqdn'         => $name->getFullyQualifiedName(),
            'interface_id' => $name->getNetworkInterface()?->getId(),
            'domain_id'    => $name->getDomain()?->getId(),
            'ttl'          => $name->getTtl(),
            'is_canonical' => $name->isCanonical(),
            'view_ids'     => $name->getViews()->map(fn($v) => $v->getId())->toArray(),
        ];
    }
}
