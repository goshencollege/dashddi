<?php

namespace App\Controller;

use App\Repository\HostRepository;
use App\Repository\IpAddressRepository;
use App\Repository\Ipv6AddressRepository;
use App\Repository\SubnetRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'dashboard')]
    public function index(
        HostRepository $hostRepo,
        SubnetRepository $subnetRepo,
        IpAddressRepository $ipRepo,
        Ipv6AddressRepository $ipv6Repo,
    ): Response {
        return $this->render('dashboard/index.html.twig', [
            'host_count'   => $hostRepo->count([]),
            'subnet_count' => $subnetRepo->count([]),
            'ip_count'     => $ipRepo->count([]),
            'ipv6_count'   => $ipv6Repo->count([]),
            'recent_hosts' => $hostRepo->findBy([], ['createdAt' => 'DESC'], 5),
        ]);
    }
}
