<?php

namespace App\Controller;

use App\Entity\Domain;
use App\Form\DomainType;
use App\Repository\DnsViewRepository;
use App\Repository\DomainRecordRepository;
use App\Repository\DomainRepository;
use App\Repository\InterfaceNameRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/domains')]
class DomainController extends AbstractController
{
    #[Route('', name: 'domain_index', methods: ['GET'])]
    public function index(DomainRepository $repo, DnsViewRepository $viewRepo): Response
    {
        return $this->render('domain/index.html.twig', [
            'domains' => $repo->findBy([], ['name' => 'ASC']),
            'views'   => $viewRepo->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'domain_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $domain = new Domain();
        $form   = $this->createForm(DomainType::class, $domain);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($domain);
            $em->flush();
            $this->addFlash('success', 'Domain "' . $domain->getName() . '" created.');
            return $this->redirectToRoute('domain_show', ['id' => $domain->getId()]);
        }

        return $this->render('domain/form.html.twig', [
            'form'   => $form,
            'domain' => $domain,
            'title'  => 'New Domain',
        ]);
    }

    private const PER_PAGE = 50;

    #[Route('/{id}', name: 'domain_show', methods: ['GET'])]
    public function show(
        Domain $domain,
        Request $request,
        DomainRecordRepository $recordRepo,
        InterfaceNameRepository $ifaceNameRepo,
    ): Response {
        $q      = trim($request->query->getString('q'));
        $rPage  = max(1, $request->query->getInt('rpage', 1));
        $nPage  = max(1, $request->query->getInt('npage', 1));

        ['records' => $records, 'total' => $recordTotal] =
            $recordRepo->searchPaginated($domain, $q, $rPage, self::PER_PAGE);

        ['names' => $ifaceNames, 'total' => $nameTotal] =
            $ifaceNameRepo->searchPaginatedForDomain($domain, $q, $nPage, self::PER_PAGE);

        $baseParams = ['id' => $domain->getId()];
        if ($q !== '') {
            $baseParams['q'] = $q;
        }

        $recordLinkParams = $baseParams;
        if ($nPage > 1) {
            $recordLinkParams['npage'] = $nPage;
        }

        $nameLinkParams = $baseParams;
        if ($rPage > 1) {
            $nameLinkParams['rpage'] = $rPage;
        }

        return $this->render('domain/show.html.twig', [
            'domain'      => $domain,
            'q'           => $q,
            'records'     => $records,
            'record_pag'  => [
                'page'        => $rPage,
                'pages'       => max(1, (int) ceil($recordTotal / self::PER_PAGE)),
                'per_page'    => self::PER_PAGE,
                'total'       => $recordTotal,
                'link_params' => $recordLinkParams,
                'page_param'  => 'rpage',
            ],
            'iface_names' => $ifaceNames,
            'name_pag'    => [
                'page'        => $nPage,
                'pages'       => max(1, (int) ceil($nameTotal / self::PER_PAGE)),
                'per_page'    => self::PER_PAGE,
                'total'       => $nameTotal,
                'link_params' => $nameLinkParams,
                'page_param'  => 'npage',
            ],
        ]);
    }

    #[Route('/{id}/edit', name: 'domain_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Domain $domain, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(DomainType::class, $domain);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Domain updated.');
            return $this->redirectToRoute('domain_show', ['id' => $domain->getId()]);
        }

        return $this->render('domain/form.html.twig', [
            'form'   => $form,
            'domain' => $domain,
            'title'  => 'Edit Domain: ' . $domain->getName(),
        ]);
    }

    #[Route('/{id}/delete', name: 'domain_delete', methods: ['POST'])]
    public function delete(Request $request, Domain $domain, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_domain_' . $domain->getId(), $request->request->get('_token'))) {
            $em->remove($domain);
            $em->flush();
            $this->addFlash('success', 'Domain deleted.');
        }
        return $this->redirectToRoute('domain_index');
    }
}
