<?php

namespace App\Controller\Api;

use App\Entity\Domain;
use App\Repository\DnsViewRepository;
use App\Repository\DomainRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/domains')]
class DomainApiController extends AbstractController
{
    #[Route('', name: 'api_domains_index', methods: ['GET'])]
    public function index(Request $request, DomainRepository $repo): JsonResponse
    {
        $qb = $repo->createQueryBuilder('d');

        if ($name = $request->query->get('name')) {
            $qb->andWhere('d.name LIKE :name')->setParameter('name', '%' . $name . '%');
        }

        $domains = $qb->orderBy('d.name', 'ASC')->getQuery()->getResult();

        return $this->json(array_map($this->serialize(...), $domains));
    }

    #[Route('/{id}', name: 'api_domains_show', methods: ['GET'])]
    public function show(Domain $domain): JsonResponse
    {
        return $this->json($this->serialize($domain));
    }

    #[Route('', name: 'api_domains_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        DnsViewRepository $viewRepo,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        if (empty($data['name'])) {
            return $this->json(['error' => 'name is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $domain = new Domain();
        $this->applyFields($domain, $data, $viewRepo);

        $em->persist($domain);
        $em->flush();

        return $this->json($this->serialize($domain), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_domains_update', methods: ['PATCH'])]
    public function update(
        Request $request,
        Domain $domain,
        EntityManagerInterface $em,
        DnsViewRepository $viewRepo,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('name', $data) && empty($data['name'])) {
            return $this->json(['error' => 'name cannot be empty'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->applyFields($domain, $data, $viewRepo, patch: true);

        $em->flush();

        return $this->json($this->serialize($domain));
    }

    #[Route('/{id}', name: 'api_domains_delete', methods: ['DELETE'])]
    public function delete(Domain $domain, EntityManagerInterface $em): JsonResponse
    {
        $em->remove($domain);
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function applyFields(Domain $domain, array $data, DnsViewRepository $viewRepo, bool $patch = false): void
    {
        $has = fn(string $k) => !$patch || array_key_exists($k, $data);

        if ($has('name') && isset($data['name']))        { $domain->setName($data['name']); }
        if ($has('description'))                          { $domain->setDescription($data['description'] ?? null); }
        if ($has('soa_nameserver'))                       { $domain->setSoaNameserver($data['soa_nameserver'] ?? null); }
        if ($has('soa_email'))                            { $domain->setSoaEmail($data['soa_email'] ?? null); }
        if ($has('soa_refresh'))                          { $domain->setSoaRefresh(isset($data['soa_refresh']) ? (int) $data['soa_refresh'] : null); }
        if ($has('soa_retry'))                            { $domain->setSoaRetry(isset($data['soa_retry']) ? (int) $data['soa_retry'] : null); }
        if ($has('soa_expire'))                           { $domain->setSoaExpire(isset($data['soa_expire']) ? (int) $data['soa_expire'] : null); }
        if ($has('soa_ttl'))                              { $domain->setSoaTtl(isset($data['soa_ttl']) ? (int) $data['soa_ttl'] : null); }
        if ($has('default_ttl'))                          { $domain->setDefaultTtl(isset($data['default_ttl']) ? (int) $data['default_ttl'] : null); }
        if ($has('exclude_from_interfaces'))               { $domain->setExcludeFromInterfaces((bool) ($data['exclude_from_interfaces'] ?? false)); }

        if ($has('view_ids')) {
            foreach ($domain->getViews()->toArray() as $view) { $domain->removeView($view); }
            foreach ($data['view_ids'] ?? [] as $id) {
                $view = $viewRepo->find($id);
                if ($view) { $domain->addView($view); }
            }
        }
    }

    private function serialize(Domain $domain): array
    {
        return [
            'id'             => $domain->getId(),
            'name'           => $domain->getName(),
            'description'    => $domain->getDescription(),
            'soa_nameserver' => $domain->getSoaNameserver(),
            'soa_email'      => $domain->getSoaEmail(),
            'soa_refresh'    => $domain->getSoaRefresh(),
            'soa_retry'      => $domain->getSoaRetry(),
            'soa_expire'     => $domain->getSoaExpire(),
            'soa_ttl'        => $domain->getSoaTtl(),
            'default_ttl'    => $domain->getDefaultTtl(),
            'exclude_from_interfaces' => $domain->isExcludeFromInterfaces(),
            'view_ids'       => $domain->getViews()->map(fn($v) => $v->getId())->toArray(),
            'created_at'     => $domain->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at'     => $domain->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'created_by'     => $domain->getCreatedBy(),
            'updated_by'     => $domain->getUpdatedBy(),
        ];
    }
}
