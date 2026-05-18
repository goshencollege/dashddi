<?php

namespace App\Controller;

use App\Repository\ClearpassServerRepository;
use App\Repository\DhcpServerRepository;
use App\Repository\DnsServerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/servers')]
class ServersController extends AbstractController
{
    #[Route('', name: 'servers_index', methods: ['GET'])]
    public function index(ClearpassServerRepository $clearpassRepo, DhcpServerRepository $dhcpRepo, DnsServerRepository $dnsRepo): Response
    {
        return $this->render('servers/index.html.twig', [
            'clearpassServers' => $clearpassRepo->findBy([], ['name' => 'ASC']),
            'dhcpServers'      => $dhcpRepo->findBy([], ['name' => 'ASC']),
            'dnsServers'       => $dnsRepo->findBy([], ['name' => 'ASC']),
        ]);
    }
}
