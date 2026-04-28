<?php

namespace App\Controller;

use App\Entity\DnsView;
use App\Form\DnsViewType;
use App\Repository\DnsViewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dns-views')]
class DnsViewController extends AbstractController
{
    #[Route('', name: 'dns_view_index', methods: ['GET'])]
    public function index(DnsViewRepository $repo): Response
    {
        return $this->render('dns_view/index.html.twig', [
            'views' => $repo->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'dns_view_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $view = new DnsView();
        $form = $this->createForm(DnsViewType::class, $view);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($view);
            $em->flush();
            $this->addFlash('success', 'DNS view "' . $view->getName() . '" created.');
            return $this->redirectToRoute('dns_view_index');
        }

        return $this->render('dns_view/form.html.twig', [
            'form'  => $form,
            'view'  => $view,
            'title' => 'New DNS View',
        ]);
    }

    #[Route('/{id}/edit', name: 'dns_view_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, DnsView $view, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(DnsViewType::class, $view);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'DNS view updated.');
            return $this->redirectToRoute('dns_view_index');
        }

        return $this->render('dns_view/form.html.twig', [
            'form'  => $form,
            'view'  => $view,
            'title' => 'Edit DNS View: ' . $view->getName(),
        ]);
    }

    #[Route('/{id}/delete', name: 'dns_view_delete', methods: ['POST'])]
    public function delete(Request $request, DnsView $view, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_dns_view_' . $view->getId(), $request->request->get('_token'))) {
            $em->remove($view);
            $em->flush();
            $this->addFlash('success', 'DNS view deleted.');
        }
        return $this->redirectToRoute('dns_view_index');
    }
}
