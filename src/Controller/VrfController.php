<?php

namespace App\Controller;

use App\Entity\Vrf;
use App\Form\VrfType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/vrfs')]
class VrfController extends AbstractController
{
    #[Route('/new', name: 'vrf_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $vrf  = new Vrf();
        $form = $this->createForm(VrfType::class, $vrf);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($vrf);
            $em->flush();
            $this->addFlash('success', 'VRF "' . $vrf->getName() . '" added.');
            return $this->redirectToRoute('subnet_index');
        }

        return $this->render('vrf/form.html.twig', [
            'form'  => $form,
            'vrf'   => $vrf,
            'title' => 'Add VRF',
        ]);
    }

    #[Route('/{id}/edit', name: 'vrf_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Vrf $vrf, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(VrfType::class, $vrf);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'VRF updated.');
            return $this->redirectToRoute('subnet_index');
        }

        return $this->render('vrf/form.html.twig', [
            'form'  => $form,
            'vrf'   => $vrf,
            'title' => 'Edit VRF: ' . $vrf->getName(),
        ]);
    }

    #[Route('/{id}/delete', name: 'vrf_delete', methods: ['POST'])]
    public function delete(Request $request, Vrf $vrf, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_vrf_' . $vrf->getId(), $request->request->get('_token'))) {
            $em->remove($vrf);
            $em->flush();
            $this->addFlash('success', 'VRF deleted.');
        }
        return $this->redirectToRoute('subnet_index');
    }
}
