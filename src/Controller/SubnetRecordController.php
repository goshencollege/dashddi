<?php

namespace App\Controller;

use App\Entity\Subnet;
use App\Entity\SubnetRecord;
use App\Form\SubnetRecordType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SubnetRecordController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('/subnet/{subnetId}/records/new', name: 'subnet_record_new')]
    public function new(int $subnetId, Request $request): Response
    {
        $subnet = $this->em->find(Subnet::class, $subnetId);
        if (!$subnet) {
            throw $this->createNotFoundException();
        }

        $record = new SubnetRecord();
        $record->setSubnet($subnet);

        foreach ($subnet->getViews() as $view) {
            $record->addView($view);
        }

        $form = $this->createForm(SubnetRecordType::class, $record);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($record);
            $this->em->flush();
            $this->addFlash('success', 'Record added.');
            return $this->redirectToRoute('subnet_show', ['id' => $subnetId]);
        }

        return $this->render('subnet_record/form.html.twig', [
            'form'   => $form,
            'subnet' => $subnet,
            'record' => $record,
        ]);
    }

    #[Route('/subnet-records/{id}/edit', name: 'subnet_record_edit')]
    public function edit(SubnetRecord $record, Request $request): Response
    {
        $subnet = $record->getSubnet();
        $form   = $this->createForm(SubnetRecordType::class, $record);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Record updated.');
            return $this->redirectToRoute('subnet_show', ['id' => $subnet->getId()]);
        }

        return $this->render('subnet_record/form.html.twig', [
            'form'   => $form,
            'subnet' => $subnet,
            'record' => $record,
        ]);
    }

    #[Route('/subnet-records/{id}/delete', name: 'subnet_record_delete', methods: ['POST'])]
    public function delete(SubnetRecord $record, Request $request): Response
    {
        $subnetId = $record->getSubnet()->getId();

        if ($this->isCsrfTokenValid('delete_subnet_record_' . $record->getId(), $request->request->get('_token'))) {
            $this->em->remove($record);
            $this->em->flush();
            $this->addFlash('success', 'Record deleted.');
        }

        return $this->redirectToRoute('subnet_show', ['id' => $subnetId]);
    }
}
