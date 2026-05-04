<?php

namespace App\Repository;

use App\Entity\AppSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AppSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppSetting::class);
    }

    /** Returns the singleton settings row, creating it if it doesn't exist yet. */
    public function getInstance(): AppSetting
    {
        $setting = $this->find(1);
        if ($setting === null) {
            $setting = new AppSetting();
            $this->getEntityManager()->persist($setting);
            $this->getEntityManager()->flush();
        }
        return $setting;
    }
}
