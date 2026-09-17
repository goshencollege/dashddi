<?php

namespace App\Controller\Api;

use App\Entity\ApiToken;
use App\Entity\Host;
use App\Repository\BuildingRepository;
use App\Repository\HostRepository;
use App\Repository\TagRepository;
use App\Service\HostQueryParser;
use App\Service\ReservedTagPrefixService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/hosts')]
class HostApiController extends AbstractController
{
    public function __construct(
        private readonly ReservedTagPrefixService $reservedPrefixes,
        private readonly HostQueryParser $queryParser,
    ) {}

    #[Route('', name: 'api_hosts_index', methods: ['GET'])]
    public function index(Request $request, HostRepository $repo): JsonResponse
    {
        $deletedParam = $request->query->get('deleted');
        $q = trim($request->query->getString('q', ''));

        if ($q !== '') {
            $orGroups = $this->queryParser->parse($q);

            if (!empty($orGroups)) {
                $hasDeletedToken = false;
                foreach ($orGroups as $conditions) {
                    foreach ($conditions as [$field, , ]) {
                        if ($field === 'deleted') {
                            $hasDeletedToken = true;
                            break 2;
                        }
                    }
                }
                $hosts = $repo->structuredSearch($orGroups);
                if (!$hasDeletedToken) {
                    $hosts = $this->filterByDeletedState($hosts, $deletedParam, $request);
                }
            } else {
                $hosts = $this->filterByDeletedState($repo->search($q), $deletedParam, $request);
            }
        } else {
            $qb = $repo->createQueryBuilder('h');
            if ($deletedParam !== 'all') {
                $qb->where($request->query->getBoolean('deleted') ? 'h.deletedAt IS NOT NULL' : 'h.deletedAt IS NULL');
            }

            if ($name = $request->query->get('name')) {
                $qb->andWhere('h.name LIKE :name')->setParameter('name', '%' . $name . '%');
            }
            if ($buildingId = $request->query->getInt('building_id')) {
                $qb->andWhere('h.building = :bid')->setParameter('bid', $buildingId);
            }

            $hosts = $qb->orderBy('h.name', 'ASC')->getQuery()->getResult();
        }

        return $this->json(array_map($this->serialize(...), $hosts));
    }

    /** @param Host[] $hosts */
    private function filterByDeletedState(array $hosts, ?string $deletedParam, Request $request): array
    {
        if ($deletedParam === 'all') {
            return $hosts;
        }
        $wantDeleted = $request->query->getBoolean('deleted');
        return array_values(array_filter($hosts, fn(Host $h) => $h->isDeleted() === $wantDeleted));
    }

    #[Route('/{id}', name: 'api_hosts_show', methods: ['GET'])]
    public function show(Host $host): JsonResponse
    {
        if ($host->isDeleted()) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }
        return $this->json($this->serialize($host));
    }

    #[Route('', name: 'api_hosts_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        BuildingRepository $buildingRepo,
        TagRepository $tagRepo,
        ValidatorInterface $validator,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        if (empty($data['name'])) {
            return $this->json(['error' => 'name is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $host = new Host();
        $host->setName($data['name']);
        $host->setRoom($data['room'] ?? null);
        $host->setDuid($data['duid'] ?? null);

        if (!empty($data['building_id'])) {
            $building = $buildingRepo->find($data['building_id']);
            if (!$building) {
                return $this->json(['error' => 'building_id not found'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $host->setBuilding($building);
        }

        foreach ($data['tag_ids'] ?? [] as $tagId) {
            $tag = $tagRepo->find($tagId);
            if ($tag) {
                $host->addTag($tag);
            }
        }

        if ($error = $this->entityErrors($validator, $host)) {
            return $this->json(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em->persist($host);
        $em->flush();

        return $this->json($this->serialize($host), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_hosts_update', methods: ['PATCH'])]
    public function update(
        Request $request,
        Host $host,
        EntityManagerInterface $em,
        BuildingRepository $buildingRepo,
        TagRepository $tagRepo,
        ValidatorInterface $validator,
    ): JsonResponse {
        if ($host->isDeleted()) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }
        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('name', $data)) {
            if (empty($data['name'])) {
                return $this->json(['error' => 'name cannot be empty'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $host->setName($data['name']);
        }

        if (array_key_exists('room', $data)) {
            $host->setRoom($data['room']);
        }

        if (array_key_exists('duid', $data)) {
            $host->setDuid($data['duid']);
        }

        if (array_key_exists('building_id', $data)) {
            if ($data['building_id'] === null) {
                $host->setBuilding(null);
            } else {
                $building = $buildingRepo->find($data['building_id']);
                if (!$building) {
                    return $this->json(['error' => 'building_id not found'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $host->setBuilding($building);
            }
        }

        if (array_key_exists('tag_ids', $data)) {
            foreach ($host->getTags()->toArray() as $tag) {
                if ($this->reservedPrefixes->matchingPrefix($tag->getName()) === null) {
                    $host->removeTag($tag);
                }
            }
            foreach ($data['tag_ids'] as $tagId) {
                $tag = $tagRepo->find($tagId);
                if ($tag) {
                    $host->addTag($tag);
                }
            }
        }

        if ($error = $this->entityErrors($validator, $host)) {
            return $this->json(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em->flush();

        return $this->json($this->serialize($host));
    }

    #[Route('/{id}', name: 'api_hosts_delete', methods: ['DELETE'])]
    public function delete(Host $host, EntityManagerInterface $em): JsonResponse
    {
        if ($host->isDeleted()) {
            return $this->json(null, Response::HTTP_NO_CONTENT);
        }
        $host->softDeleteWithInterfaces();
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/restore', name: 'api_hosts_restore', methods: ['POST'])]
    public function restore(Host $host, EntityManagerInterface $em): JsonResponse
    {
        if (!$host->isDeleted()) {
            return $this->json($this->serialize($host));
        }
        $host->restore();
        foreach ($host->getInterfaces() as $iface) {
            $iface->restore();
        }
        $em->flush();

        return $this->json($this->serialize($host));
    }

    #[Route('/{id}/token', name: 'api_hosts_token_generate', methods: ['POST'])]
    public function generateToken(Host $host, EntityManagerInterface $em): JsonResponse
    {
        if ($host->isDeleted()) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        // Remove existing token first so the unique host_id constraint doesn't conflict
        $existing = $host->getApiToken();
        if ($existing !== null) {
            $em->remove($existing);
            $em->flush();
            $em->refresh($host); // clear stale inverse-side reference after deletion
        }

        $raw = bin2hex(random_bytes(32));

        $token = new ApiToken();
        $token->setName('Host token: ' . $host->getName());
        $token->setOwnerIdentifier($this->getUser()->getUserIdentifier());
        $token->setTokenHash(hash('sha256', $raw));
        $token->setHost($host);
        $token->setAllowedRoutes([]);
        $token->setAllowedCidrs([]);

        $em->persist($token);
        $em->flush();

        return $this->json([
            'token'   => $raw,
            'id'      => $token->getId(),
            'host_id' => $host->getId(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}/token', name: 'api_hosts_token_revoke', methods: ['DELETE'])]
    public function revokeToken(Host $host, EntityManagerInterface $em): JsonResponse
    {
        if ($host->isDeleted()) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        $token = $host->getApiToken();
        if ($token === null) {
            return $this->json(['error' => 'This host has no API token.'], Response::HTTP_NOT_FOUND);
        }

        $em->remove($token);
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function entityErrors(ValidatorInterface $validator, Host $host): ?string
    {
        $violations = $validator->validate($host);
        if (count($violations) === 0) {
            return null;
        }
        $messages = [];
        foreach ($violations as $v) {
            $messages[] = ($v->getPropertyPath() ? $v->getPropertyPath() . ': ' : '') . $v->getMessage();
        }
        return implode('; ', $messages);
    }

    private function serialize(Host $host): array
    {
        return [
            'id'           => $host->getId(),
            'name'         => $host->getName(),
            'room'         => $host->getRoom(),
            'duid'         => $host->getDuid(),
            'duid_display' => $host->getDuidDisplay(),
            'building_id'  => $host->getBuilding()?->getId(),
            'tag_ids'      => $host->getTags()->map(fn($t) => $t->getId())->toArray(),
            'deleted_at'   => $host->getDeletedAt()?->format(\DateTimeInterface::ATOM),
            'created_at'   => $host->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at'   => $host->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'created_by'   => $host->getCreatedBy(),
            'updated_by'   => $host->getUpdatedBy(),
        ];
    }
}
