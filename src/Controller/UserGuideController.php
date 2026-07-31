<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user-guide', name: 'user_guide_')]
class UserGuideController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(): Response
    {
        return $this->render('user_guide/index.html.twig');
    }

    #[Route('/hosts', name: 'hosts')]
    public function hosts(): Response
    {
        return $this->render('user_guide/hosts.html.twig');
    }

    #[Route('/subnets', name: 'subnets')]
    public function subnets(): Response
    {
        return $this->render('user_guide/subnets.html.twig');
    }

    #[Route('/dns', name: 'dns')]
    public function dns(): Response
    {
        return $this->render('user_guide/dns.html.twig');
    }

    #[Route('/dhcp', name: 'dhcp')]
    public function dhcp(): Response
    {
        return $this->render('user_guide/dhcp.html.twig');
    }

    #[Route('/servers', name: 'servers')]
    public function servers(): Response
    {
        return $this->render('user_guide/servers.html.twig');
    }

    #[Route('/integrations', name: 'integrations')]
    public function integrations(): RedirectResponse
    {
        return $this->redirectToRoute('user_guide_servers', [], 301);
    }

    #[Route('/settings', name: 'settings')]
    public function settings(): Response
    {
        return $this->render('user_guide/settings.html.twig');
    }

    #[Route('/recommendations', name: 'recommendations')]
    public function recommendations(): Response
    {
        return $this->render('user_guide/recommendations.html.twig');
    }
}
