<?php

namespace App\Controller;

use App\Repository\PushLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/push-logs')]
class PushLogController extends AbstractController
{
    #[Route('', name: 'push_log_index', methods: ['GET'])]
    public function index(PushLogRepository $repo): Response
    {
        return $this->render('push_log/index.html.twig', [
            'logs' => $repo->findRecent(200),
        ]);
    }
}
