<?php

namespace App\Controller;

use App\Entity\Domain;
use App\Entity\DomainRecord;
use App\Form\DomainRecordType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DomainRecordController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('/domain/{domainId}/records/new', name: 'domain_record_new')]
    public function new(int $domainId, Request $request): Response
    {
        $domain = $this->em->find(Domain::class, $domainId);
        if (!$domain) {
            throw $this->createNotFoundException();
        }

        $record = new DomainRecord();
        $record->setDomain($domain);

        $form = $this->createForm(DomainRecordType::class, $record);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($record);
            $this->em->flush();
            $this->addFlash('success', 'Record added.');
            return $this->redirectToRoute('domain_show', ['id' => $domainId]);
        }

        return $this->render('domain_record/form.html.twig', [
            'form'   => $form,
            'domain' => $domain,
            'record' => $record,
        ]);
    }

    #[Route('/domain-records/{id}/edit', name: 'domain_record_edit')]
    public function edit(DomainRecord $record, Request $request): Response
    {
        $domain = $record->getDomain();
        $form   = $this->createForm(DomainRecordType::class, $record);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Record updated.');
            return $this->redirectToRoute('domain_show', ['id' => $domain->getId()]);
        }

        return $this->render('domain_record/form.html.twig', [
            'form'   => $form,
            'domain' => $domain,
            'record' => $record,
        ]);
    }

    #[Route('/domain-records/{id}/delete', name: 'domain_record_delete', methods: ['POST'])]
    public function delete(DomainRecord $record, Request $request): Response
    {
        $domainId = $record->getDomain()->getId();

        if ($this->isCsrfTokenValid('delete_record_' . $record->getId(), $request->request->get('_token'))) {
            $this->em->remove($record);
            $this->em->flush();
            $this->addFlash('success', 'Record deleted.');
        }

        return $this->redirectToRoute('domain_show', ['id' => $domainId]);
    }
}
