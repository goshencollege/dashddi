<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ApiDocsController extends AbstractController
{
    #[Route('/api-docs', name: 'api_docs', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('api_docs/index.html.twig');
    }
}
