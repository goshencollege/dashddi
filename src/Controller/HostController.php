<?php

namespace App\Controller;

use App\Entity\Host;
use App\Form\HostType;
use App\Repository\HostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/hosts')]
class HostController extends AbstractController
{
    #[Route('', name: 'host_index', methods: ['GET'])]
    public function index(HostRepository $repo): Response
    {
        return $this->render('host/index.html.twig', [
            'hosts' => $repo->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'host_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $host = new Host();
        $form = $this->createForm(HostType::class, $host);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($host);
            $em->flush();
            $this->addFlash('success', 'Host "' . $host->getName() . '" created.');
            return $this->redirectToRoute('host_show', ['id' => $host->getId()]);
        }

        return $this->render('host/form.html.twig', [
            'form'  => $form,
            'host'  => $host,
            'title' => 'New Host',
        ]);
    }

    #[Route('/{id}', name: 'host_show', methods: ['GET'])]
    public function show(Host $host): Response
    {
        return $this->render('host/show.html.twig', ['host' => $host]);
    }

    #[Route('/{id}/edit', name: 'host_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Host $host, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(HostType::class, $host);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Host updated.');
            return $this->redirectToRoute('host_show', ['id' => $host->getId()]);
        }

        return $this->render('host/form.html.twig', [
            'form'  => $form,
            'host'  => $host,
            'title' => 'Edit Host: ' . $host->getName(),
        ]);
    }

    #[Route('/{id}/delete', name: 'host_delete', methods: ['POST'])]
    public function delete(Request $request, Host $host, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_host_' . $host->getId(), $request->request->get('_token'))) {
            $em->remove($host);
            $em->flush();
            $this->addFlash('success', 'Host deleted.');
        }
        return $this->redirectToRoute('host_index');
    }
}
