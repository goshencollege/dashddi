<?php

namespace App\Controller\Api;

use App\Entity\AddressBlock;
use App\Enum\BlockType;
use App\Repository\AddressBlockRepository;
use App\Repository\SubnetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/address-blocks')]
class AddressBlockApiController extends AbstractController
{
    #[Route('', name: 'api_address_blocks_index', methods: ['GET'])]
    public function index(Request $request, AddressBlockRepository $repo): JsonResponse
    {
        $qb = $repo->createQueryBuilder('b');

        if ($subnetId = $request->query->getInt('subnet_id')) {
            $qb->andWhere('b.subnet = :sid')->setParameter('sid', $subnetId);
        }
        if ($type = $request->query->get('type')) {
            $qb->andWhere('b.type = :type')->setParameter('type', $type);
        }

        $blocks = $qb->orderBy('b.startIp', 'ASC')->getQuery()->getResult();

        return $this->json(array_map($this->serialize(...), $blocks));
    }

    #[Route('/{id}', name: 'api_address_blocks_show', methods: ['GET'])]
    public function show(AddressBlock $addressBlock): JsonResponse
    {
        return $this->json($this->serialize($addressBlock));
    }

    #[Route('', name: 'api_address_blocks_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        SubnetRepository $subnetRepo,
        AddressBlockRepository $blockRepo,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        if (empty($data['subnet_id'])) {
            return $this->json(['error' => 'subnet_id is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (empty($data['start_ip'])) {
            return $this->json(['error' => 'start_ip is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (empty($data['end_ip'])) {
            return $this->json(['error' => 'end_ip is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $subnet = $subnetRepo->find($data['subnet_id']);
        if (!$subnet) {
            return $this->json(['error' => 'subnet_id not found'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $type = BlockType::tryFrom($data['type'] ?? '');
        if (!$type) {
            return $this->json(
                ['error' => 'type must be one of: ' . implode(', ', array_column(BlockType::cases(), 'value'))],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $block = new AddressBlock();
        $block->setSubnet($subnet);
        $block->setType($type);
        $block->setStartIp($data['start_ip']);
        $block->setEndIp($data['end_ip']);
        $block->setLabel($data['label'] ?? null);

        if ($error = $this->validateBlock($block, $blockRepo)) {
            return $this->json(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em->persist($block);
        $em->flush();

        return $this->json($this->serialize($block), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_address_blocks_update', methods: ['PATCH'])]
    public function update(
        Request $request,
        AddressBlock $addressBlock,
        EntityManagerInterface $em,
        SubnetRepository $subnetRepo,
        AddressBlockRepository $blockRepo,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('subnet_id', $data)) {
            $subnet = $subnetRepo->find($data['subnet_id']);
            if (!$subnet) {
                return $this->json(['error' => 'subnet_id not found'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $addressBlock->setSubnet($subnet);
        }

        if (array_key_exists('type', $data)) {
            $type = BlockType::tryFrom($data['type'] ?? '');
            if (!$type) {
                return $this->json(
                    ['error' => 'type must be one of: ' . implode(', ', array_column(BlockType::cases(), 'value'))],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
            $addressBlock->setType($type);
        }

        if (array_key_exists('start_ip', $data)) {
            if (empty($data['start_ip'])) {
                return $this->json(['error' => 'start_ip cannot be empty'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $addressBlock->setStartIp($data['start_ip']);
        }

        if (array_key_exists('end_ip', $data)) {
            if (empty($data['end_ip'])) {
                return $this->json(['error' => 'end_ip cannot be empty'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $addressBlock->setEndIp($data['end_ip']);
        }

        if (array_key_exists('label', $data)) {
            $addressBlock->setLabel($data['label']);
        }

        if ($error = $this->validateBlock($addressBlock, $blockRepo)) {
            return $this->json(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em->flush();

        return $this->json($this->serialize($addressBlock));
    }

    #[Route('/{id}', name: 'api_address_blocks_delete', methods: ['DELETE'])]
    public function delete(AddressBlock $addressBlock, EntityManagerInterface $em): JsonResponse
    {
        $em->remove($addressBlock);
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function validateBlock(AddressBlock $block, AddressBlockRepository $repo): ?string
    {
        $subnet = $block->getSubnet();
        if (!$subnet?->getId()) {
            return null;
        }

        $overlap = $repo->findOverlappingBlock(
            $subnet->getId(),
            $block->getStartIp(),
            $block->getEndIp(),
            $block->getId(),
        );

        if ($overlap) {
            $label = $overlap->getLabel() ? " \"{$overlap->getLabel()}\"" : '';
            return sprintf(
                'Block overlaps with existing %s block%s (%s–%s).',
                $overlap->getType()->value,
                $label,
                $overlap->getStartIp(),
                $overlap->getEndIp(),
            );
        }

        return null;
    }

    private function serialize(AddressBlock $block): array
    {
        return [
            'id'         => $block->getId(),
            'subnet_id'  => $block->getSubnet()?->getId(),
            'type'       => $block->getType()->value,
            'label'      => $block->getLabel(),
            'start_ip'   => $block->getStartIp(),
            'end_ip'     => $block->getEndIp(),
            'created_at' => $block->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $block->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'created_by' => $block->getCreatedBy(),
            'updated_by' => $block->getUpdatedBy(),
        ];
    }
}
