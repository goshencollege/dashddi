<?php

namespace App\Controller;

use App\Entity\DnssecPolicy;
use App\Form\DnssecPolicyType;
use App\Repository\DnssecPolicyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dnssec-policies')]
class DnssecPolicyController extends AbstractController
{
    #[Route('', name: 'dnssec_policy_index', methods: ['GET'])]
    public function index(DnssecPolicyRepository $repo): Response
    {
        return $this->render('dnssec_policy/index.html.twig', [
            'policies' => $repo->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'dnssec_policy_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $policy = new DnssecPolicy();
        $form   = $this->createForm(DnssecPolicyType::class, $policy);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($policy);
            $em->flush();
            $this->addFlash('success', 'DNSSEC policy "' . $policy->getName() . '" created.');
            return $this->redirectToRoute('dnssec_policy_index');
        }

        return $this->render('dnssec_policy/form.html.twig', [
            'form'   => $form,
            'policy' => $policy,
            'title'  => 'New DNSSEC Policy',
        ]);
    }

    #[Route('/{id}/edit', name: 'dnssec_policy_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, DnssecPolicy $policy, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(DnssecPolicyType::class, $policy);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'DNSSEC policy updated.');
            return $this->redirectToRoute('dnssec_policy_index');
        }

        return $this->render('dnssec_policy/form.html.twig', [
            'form'   => $form,
            'policy' => $policy,
            'title'  => 'Edit DNSSEC Policy: ' . $policy->getName(),
        ]);
    }

    #[Route('/{id}/delete', name: 'dnssec_policy_delete', methods: ['POST'])]
    public function delete(Request $request, DnssecPolicy $policy, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_dnssec_policy_' . $policy->getId(), $request->request->get('_token'))) {
            $em->remove($policy);
            $em->flush();
            $this->addFlash('success', 'DNSSEC policy deleted.');
        }

        return $this->redirectToRoute('dnssec_policy_index');
    }
}
