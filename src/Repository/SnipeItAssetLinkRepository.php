<?php

namespace App\Repository;

use App\Entity\SnipeItAssetLink;
use App\Entity\SnipeItServer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SnipeItAssetLink> */
class SnipeItAssetLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SnipeItAssetLink::class);
    }

    /** @return SnipeItAssetLink[] */
    public function findByServer(SnipeItServer $server): array
    {
        return $this->findBy(['server' => $server]);
    }

    public function findByServerAndAssetId(SnipeItServer $server, int $assetId): ?SnipeItAssetLink
    {
        return $this->findOneBy(['server' => $server, 'snipeAssetId' => $assetId]);
    }
}
