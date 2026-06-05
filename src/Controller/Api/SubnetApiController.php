<?php

namespace App\Controller\Api;

use App\Entity\Subnet;
use App\Repository\DnsViewRepository;
use App\Repository\SubnetRepository;
use App\Repository\TagRepository;
use App\Repository\VrfRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/subnets')]
class SubnetApiController extends AbstractController
{
    #[Route('', name: 'api_subnets_index', methods: ['GET'])]
    public function index(Request $request, SubnetRepository $repo): JsonResponse
    {
        $qb = $repo->createQueryBuilder('s');

        if ($name = $request->query->get('name')) {
            $qb->andWhere('s.name LIKE :name')->setParameter('name', '%' . $name . '%');
        }
        if ($vlan = $request->query->getInt('vlan')) {
            $qb->andWhere('s.vlan = :vlan')->setParameter('vlan', $vlan);
        }
        if ($vrfId = $request->query->getInt('vrf_id')) {
            $qb->andWhere('s.vrf = :vrf')->setParameter('vrf', $vrfId);
        }

        $subnets = $qb->orderBy('s.name', 'ASC')->getQuery()->getResult();

        return $this->json(array_map($this->serialize(...), $subnets));
    }

    #[Route('/{id}', name: 'api_subnets_show', methods: ['GET'])]
    public function show(Subnet $subnet): JsonResponse
    {
        return $this->json($this->serialize($subnet));
    }

    #[Route('', name: 'api_subnets_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        VrfRepository $vrfRepo,
        TagRepository $tagRepo,
        DnsViewRepository $viewRepo,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        if (empty($data['name'])) {
            return $this->json(['error' => 'name is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $subnet = new Subnet();
        $this->applyFields($subnet, $data, $vrfRepo, $tagRepo, $viewRepo);

        if ($error = $this->validateCidrs($subnet, $repo)) {
            return $this->json(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em->persist($subnet);
        $em->flush();

        return $this->json($this->serialize($subnet), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_subnets_update', methods: ['PATCH'])]
    public function update(
        Request $request,
        Subnet $subnet,
        EntityManagerInterface $em,
        VrfRepository $vrfRepo,
        TagRepository $tagRepo,
        DnsViewRepository $viewRepo,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('name', $data) && empty($data['name'])) {
            return $this->json(['error' => 'name cannot be empty'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->applyFields($subnet, $data, $vrfRepo, $tagRepo, $viewRepo, patch: true);

        if ($error = $this->validateCidrs($subnet, $repo)) {
            return $this->json(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em->flush();

        return $this->json($this->serialize($subnet));
    }

    #[Route('/{id}', name: 'api_subnets_delete', methods: ['DELETE'])]
    public function delete(Subnet $subnet, EntityManagerInterface $em): JsonResponse
    {
        $em->remove($subnet);
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function applyFields(
        Subnet $subnet,
        array $data,
        VrfRepository $vrfRepo,
        TagRepository $tagRepo,
        DnsViewRepository $viewRepo,
        bool $patch = false,
    ): void {
        $has = fn(string $k) => !$patch || array_key_exists($k, $data);

        if ($has('name') && isset($data['name']))        { $subnet->setName($data['name']); }
        if ($has('description'))                          { $subnet->setDescription($data['description'] ?? null); }
        if ($has('ipv4_cidr'))                            { $subnet->setIpv4Cidr($data['ipv4_cidr'] ?? null); }
        if ($has('ipv6_cidr'))                            { $subnet->setIpv6Cidr($data['ipv6_cidr'] ?? null); }
        if ($has('vlan'))                                 { $subnet->setVlan(isset($data['vlan']) ? (int) $data['vlan'] : null); }
        if ($has('gateway'))                              { $subnet->setGateway($data['gateway'] ?? null); }
        if ($has('lease_retention_days'))                 { $subnet->setLeaseRetentionDays(isset($data['lease_retention_days']) ? (int) $data['lease_retention_days'] : null); }
        if ($has('is_container'))                         { $subnet->setIsContainer((bool) ($data['is_container'] ?? false)); }

        if ($has('vrf_id')) {
            $subnet->setVrf(($data['vrf_id'] ?? null) ? $vrfRepo->find($data['vrf_id']) : null);
        }

        if ($has('tag_ids')) {
            foreach ($subnet->getTags()->toArray() as $tag) { $subnet->removeTag($tag); }
            foreach ($data['tag_ids'] ?? [] as $id) {
                $tag = $tagRepo->find($id);
                if ($tag) { $subnet->addTag($tag); }
            }
        }

        if ($has('view_ids')) {
            foreach ($subnet->getViews()->toArray() as $view) { $subnet->removeView($view); }
            foreach ($data['view_ids'] ?? [] as $id) {
                $view = $viewRepo->find($id);
                if ($view) { $subnet->addView($view); }
            }
        }
    }

    private function validateCidrs(Subnet $subnet, SubnetRepository $repo): ?string
    {
        if ($subnet->isContainer()) {
            return null;
        }

        $excludeId = $subnet->getId();

        foreach ([4 => $subnet->getIpv4Cidr(), 6 => $subnet->getIpv6Cidr()] as $version => $cidr) {
            if ($cidr && ($overlap = $repo->findTerminalCidrOverlap($cidr, $excludeId))) {
                return sprintf('IPv%d CIDR %s overlaps with terminal subnet "%s".', $version, $cidr, $overlap->getName());
            }
        }

        return null;
    }

    private function serialize(Subnet $subnet): array
    {
        return [
            'id'                    => $subnet->getId(),
            'name'                  => $subnet->getName(),
            'ipv4_cidr'             => $subnet->getIpv4Cidr(),
            'ipv6_cidr'             => $subnet->getIpv6Cidr(),
            'description'           => $subnet->getDescription(),
            'vlan'                  => $subnet->getVlan(),
            'gateway'               => $subnet->getGateway(),
            'vrf_id'                => $subnet->getVrf()?->getId(),
            'lease_retention_days'  => $subnet->getLeaseRetentionDays(),
            'is_container'          => $subnet->isContainer(),
            'tag_ids'               => $subnet->getTags()->map(fn($t) => $t->getId())->toArray(),
            'view_ids'              => $subnet->getViews()->map(fn($v) => $v->getId())->toArray(),
            'created_at'            => $subnet->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at'            => $subnet->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'created_by'            => $subnet->getCreatedBy(),
            'updated_by'            => $subnet->getUpdatedBy(),
        ];
    }
}
