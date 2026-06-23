<?php

namespace App\Controller;

use App\Entity\Domain;
use App\Entity\DomainRecord;
use App\Entity\NetworkInterface;
use App\Enum\RecordType;
use App\Form\DomainRecordType;
use App\Repository\DomainRecordRepository;
use App\Service\FcrdnsChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DomainRecordController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FcrdnsChecker          $fcrdnsChecker,
        private readonly DomainRecordRepository $recordRepo,
    ) {}

    #[Route('/domain/{domainId}/records/new', name: 'domain_record_new')]
    public function new(int $domainId, Request $request): Response
    {
        $domain = $this->em->find(Domain::class, $domainId);
        if (!$domain) {
            throw $this->createNotFoundException();
        }

        $record = new DomainRecord();
        $record->setDomain($domain);

        foreach ($domain->getViews() as $view) {
            $record->addView($view);
        }

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

    #[Route('/interfaces/{id}/dns-records/new', name: 'interface_domain_record_new', methods: ['GET', 'POST'])]
    public function interfaceNew(Request $request, NetworkInterface $interface): Response
    {
        $record = new DomainRecord();
        $record->setNetworkInterface($interface);

        $form = $this->createForm(DomainRecordType::class, $record, [
            'network_interface' => $interface,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->autoSetCanonical($record);
            $this->em->persist($record);
            $this->em->flush();

            if ($record->isCanonical()) {
                $this->enforceCanonicalUniqueness($record);
                $fcrdnsError = $this->checkCanonical($record);
                if ($fcrdnsError !== null) {
                    $this->addFlash('warning', 'FCrDNS check failed — record saved as canonical anyway. ' . $fcrdnsError);
                }
            }

            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true]);
            }
            $this->addFlash('success', 'DNS record added.');
            return $this->redirectToRoute('interface_show', ['id' => $interface->getId()]);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'success' => false,
                'html'    => $this->renderView('domain_record/_interface_modal_form.html.twig', [
                    'form'      => $form,
                    'interface' => $interface,
                    'record'    => $record,
                ]),
            ]);
        }

        return $this->render('domain_record/interface_form.html.twig', [
            'form'      => $form,
            'interface' => $interface,
            'record'    => $record,
            'title'     => 'Add DNS Record',
        ]);
    }

    #[Route('/domain-records/{id}/edit', name: 'domain_record_edit')]
    public function edit(DomainRecord $record, Request $request): Response
    {
        $domain    = $record->getDomain();
        $interface = $record->getNetworkInterface();

        $form = $this->createForm(DomainRecordType::class, $record, [
            'network_interface' => $interface,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();

            if ($interface !== null && $record->isCanonical()) {
                $this->enforceCanonicalUniqueness($record);
                $fcrdnsError = $this->checkCanonical($record);
                if ($fcrdnsError !== null) {
                    $this->addFlash('warning', 'FCrDNS check failed — record saved as canonical anyway. ' . $fcrdnsError);
                }
            }

            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true]);
            }

            $this->addFlash('success', 'Record updated.');

            if ($interface !== null) {
                return $this->redirectToRoute('interface_show', ['id' => $interface->getId()]);
            }
            return $this->redirectToRoute('domain_show', ['id' => $domain->getId()]);
        }

        if ($interface !== null && $request->isXmlHttpRequest()) {
            return $this->json([
                'success' => false,
                'html'    => $this->renderView('domain_record/_interface_modal_form.html.twig', [
                    'form'      => $form,
                    'interface' => $interface,
                    'record'    => $record,
                ]),
            ]);
        }

        if ($interface !== null) {
            return $this->render('domain_record/interface_form.html.twig', [
                'form'      => $form,
                'interface' => $interface,
                'record'    => $record,
                'title'     => 'Edit DNS Record',
            ]);
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
        $domainId  = $record->getDomain()->getId();
        $interface = $record->getNetworkInterface();

        if ($this->isCsrfTokenValid('delete_record_' . $record->getId(), $request->request->get('_token'))) {
            $this->em->remove($record);
            $this->em->flush();
            if (!$request->isXmlHttpRequest()) {
                $this->addFlash('success', 'Record deleted.');
            }
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true]);
        }

        if ($interface !== null) {
            return $this->redirectToRoute('interface_show', ['id' => $interface->getId()]);
        }
        return $this->redirectToRoute('domain_show', ['id' => $domainId]);
    }

    private function autoSetCanonical(DomainRecord $record): void
    {
        $iface = $record->getNetworkInterface();
        if (!$iface) {
            return;
        }
        $type = $record->getType();
        if ($type !== RecordType::A && $type !== RecordType::AAAA) {
            return;
        }
        if (!$this->recordRepo->hasAnyForInterface($iface, $type)) {
            $record->setIsCanonical(true);
        }
    }

    private function enforceCanonicalUniqueness(DomainRecord $record): void
    {
        if (!$record->isCanonical() || !$record->getNetworkInterface()) {
            return;
        }
        $this->em->createQueryBuilder()
            ->update(DomainRecord::class, 'r')
            ->set('r.isCanonical', ':false')
            ->where('r.networkInterface = :iface')
            ->andWhere('r.type = :type')
            ->andWhere('r.id != :id')
            ->setParameter('false', false)
            ->setParameter('iface', $record->getNetworkInterface())
            ->setParameter('type', $record->getType())
            ->setParameter('id', $record->getId())
            ->getQuery()
            ->execute();
    }

    private function checkCanonical(DomainRecord $record): ?string
    {
        if (!$record->isCanonical()) {
            return null;
        }
        if ($record->getDomain() === null) {
            return 'A domain is required for canonical records.';
        }
        $iface = $record->getNetworkInterface();
        return $this->fcrdnsChecker->check(
            $record->getFullyQualifiedHostname(),
            $iface?->getIpAddress()?->getAddress(),
            $iface?->getIpv6Address()?->getAddress(),
        );
    }
}
