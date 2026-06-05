<?php

namespace App\Repository;

use App\Entity\AddressBlock;
use App\Enum\BlockType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use IPLib\Factory;

class AddressBlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AddressBlock::class);
    }

    /** @return AddressBlock[] */
    public function findBySubnet(int $subnetId): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.subnet = :id')
            ->setParameter('id', $subnetId)
            ->orderBy('b.startIp', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return AddressBlock[] */
    public function findFixedBySubnet(int $subnetId): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.subnet = :id')
            ->andWhere('b.type = :type')
            ->setParameter('id', $subnetId)
            ->setParameter('type', BlockType::Fixed)
            ->getQuery()
            ->getResult();
    }

    /**
     * Return the first block in the subnet whose IP range overlaps [$startIp, $endIp],
     * excluding $excludeId (pass the block's own ID when editing so it doesn't conflict with itself).
     */
    public function findOverlappingBlock(int $subnetId, string $startIp, string $endIp, ?int $excludeId = null): ?AddressBlock
    {
        $newStart = Factory::parseAddressString($startIp);
        $newEnd   = Factory::parseAddressString($endIp);

        if (!$newStart || !$newEnd) {
            return null;
        }

        $qb = $this->createQueryBuilder('b')
            ->where('b.subnet = :subnetId')
            ->setParameter('subnetId', $subnetId);
        if ($excludeId !== null) {
            $qb->andWhere('b.id != :id')->setParameter('id', $excludeId);
        }

        foreach ($qb->getQuery()->getResult() as $block) {
            $existStart = Factory::parseAddressString($block->getStartIp());
            $existEnd   = Factory::parseAddressString($block->getEndIp());

            if (!$existStart || !$existEnd) {
                continue;
            }
            if ($newStart->getAddressType() !== $existStart->getAddressType()) {
                continue;
            }
            if ($newStart->getComparableString() <= $existEnd->getComparableString()
                && $existStart->getComparableString() <= $newEnd->getComparableString()) {
                return $block;
            }
        }

        return null;
    }

    /** @return AddressBlock[] Returns Fixed and Reserved blocks for manual IP validation */
    public function findFixedOrReservedBySubnet(int $subnetId): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.subnet = :id')
            ->andWhere('b.type IN (:types)')
            ->setParameter('id', $subnetId)
            ->setParameter('types', [BlockType::Fixed, BlockType::Reserved])
            ->getQuery()
            ->getResult();
    }
}
