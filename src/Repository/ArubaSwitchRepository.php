<?php

namespace App\Repository;

use App\Entity\ArubaSwitch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ArubaSwitchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArubaSwitch::class);
    }

    public function findByManagementIp(string $ip): ?ArubaSwitch
    {
        return $this->findOneBy(['managementIp' => $ip]);
    }
}
