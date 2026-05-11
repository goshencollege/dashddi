<?php

namespace App\Repository;

use App\Entity\BackupSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class BackupSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BackupSetting::class);
    }

    public function getInstance(): BackupSetting
    {
        $setting = $this->find(1);
        if ($setting === null) {
            $setting = new BackupSetting();
            $this->getEntityManager()->persist($setting);
            $this->getEntityManager()->flush();
        }
        return $setting;
    }
}
