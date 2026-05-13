<?php

namespace App\Controller;

use App\Entity\RadiusClient;
use App\Form\RadiusClientType;
use App\Repository\RadiusClientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/settings/radius-clients', name: 'radius_client_')]
class RadiusClientController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(RadiusClientRepository $repo): Response
    {
        return $this->render('radius_client/index.html.twig', [
            'clients' => $repo->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $client = new RadiusClient();
        $form   = $this->createForm(RadiusClientType::class, $client, ['is_new' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($client);
            $em->flush();
            $this->addFlash('success', 'RADIUS client "' . $client->getName() . '" added. Restart the freeradius container to apply.');
            return $this->redirectToRoute('radius_client_index');
        }

        return $this->render('radius_client/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, RadiusClient $client, EntityManagerInterface $em): Response
    {
        $originalSecret = $client->getSecret();
        $form = $this->createForm(RadiusClientType::class, $client, ['is_new' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($client->getSecret() === '') {
                $client->setSecret($originalSecret);
            }
            $em->flush();
            $this->addFlash('success', 'RADIUS client "' . $client->getName() . '" updated. Restart the freeradius container to apply.');
            return $this->redirectToRoute('radius_client_index');
        }

        return $this->render('radius_client/edit.html.twig', ['form' => $form, 'client' => $client]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, RadiusClient $client, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_radius_client_' . $client->getId(), $request->request->get('_token'))) {
            $em->remove($client);
            $em->flush();
            $this->addFlash('success', 'RADIUS client "' . $client->getName() . '" removed. Restart the freeradius container to apply.');
        }

        return $this->redirectToRoute('radius_client_index');
    }
}
