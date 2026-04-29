<?php

namespace App\Controller;

use App\Entity\DnsAcl;
use App\Form\DnsAclType;
use App\Repository\DnsAclRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dns-acls')]
class DnsAclController extends AbstractController
{
    #[Route('', name: 'dns_acl_index', methods: ['GET'])]
    public function index(DnsAclRepository $repo): Response
    {
        return $this->render('dns_acl/index.html.twig', [
            'acls' => $repo->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'dns_acl_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $acl  = new DnsAcl();
        $form = $this->createForm(DnsAclType::class, $acl);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($acl);
            $em->flush();
            $this->addFlash('success', 'ACL "' . $acl->getName() . '" created.');
            return $this->redirectToRoute('dns_acl_index');
        }

        return $this->render('dns_acl/form.html.twig', [
            'form'  => $form,
            'acl'   => $acl,
            'title' => 'New DNS ACL',
        ]);
    }

    #[Route('/{id}/edit', name: 'dns_acl_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, DnsAcl $acl, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(DnsAclType::class, $acl);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'ACL updated.');
            return $this->redirectToRoute('dns_acl_index');
        }

        return $this->render('dns_acl/form.html.twig', [
            'form'  => $form,
            'acl'   => $acl,
            'title' => 'Edit ACL: ' . $acl->getName(),
        ]);
    }

    #[Route('/{id}/delete', name: 'dns_acl_delete', methods: ['POST'])]
    public function delete(Request $request, DnsAcl $acl, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_dns_acl_' . $acl->getId(), $request->request->get('_token'))) {
            $em->remove($acl);
            $em->flush();
            $this->addFlash('success', 'ACL deleted.');
        }

        return $this->redirectToRoute('dns_acl_index');
    }
}
