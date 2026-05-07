<?php

namespace App\Controller\Api;

use App\Entity\Building;
use App\Repository\BuildingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/buildings')]
class BuildingApiController extends AbstractController
{
    #[Route('', name: 'api_buildings_index', methods: ['GET'])]
    public function index(Request $request, BuildingRepository $repo): JsonResponse
    {
        $qb = $repo->createQueryBuilder('b');

        if ($name = $request->query->get('name')) {
            $qb->andWhere('b.name LIKE :name')->setParameter('name', '%' . $name . '%');
        }

        $buildings = $qb->orderBy('b.name', 'ASC')->getQuery()->getResult();

        return $this->json(array_map($this->serialize(...), $buildings));
    }

    #[Route('/{id}', name: 'api_buildings_show', methods: ['GET'])]
    public function show(Building $building): JsonResponse
    {
        return $this->json($this->serialize($building));
    }

    #[Route('', name: 'api_buildings_create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        if (empty($data['name'])) {
            return $this->json(['error' => 'name is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $building = new Building();
        $building->setName($data['name']);
        $building->setDescription($data['description'] ?? null);

        $em->persist($building);
        $em->flush();

        return $this->json($this->serialize($building), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_buildings_update', methods: ['PATCH'])]
    public function update(Request $request, Building $building, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('name', $data)) {
            if (empty($data['name'])) {
                return $this->json(['error' => 'name cannot be empty'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $building->setName($data['name']);
        }

        if (array_key_exists('description', $data)) {
            $building->setDescription($data['description']);
        }

        $em->flush();

        return $this->json($this->serialize($building));
    }

    #[Route('/{id}', name: 'api_buildings_delete', methods: ['DELETE'])]
    public function delete(Building $building, EntityManagerInterface $em): JsonResponse
    {
        $em->remove($building);
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function serialize(Building $building): array
    {
        return [
            'id'          => $building->getId(),
            'name'        => $building->getName(),
            'description' => $building->getDescription(),
        ];
    }
}
