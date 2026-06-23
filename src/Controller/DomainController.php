<?php

namespace App\Controller;

use App\Entity\Domain;
use App\Enum\RecordType;
use App\Form\DomainType;
use App\Repository\DnsViewRepository;
use App\Repository\DomainRecordRepository;
use App\Repository\DomainRepository;
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
        DnsViewRepository $viewRepo,
    ): Response {
        $page  = max(1, $request->query->getInt('page', 1));
        $reset = $request->query->getBoolean('reset');

        $advancedFields = ['hostname', 'type', 'value', 'host', 'view'];
        $q          = '';
        $criteria   = [];
        $isAdvanced = false;

        if ($reset) {
            return $this->redirectToRoute('domain_show', ['id' => $domain->getId()]);
        }

        $hasExplicitState = $request->query->has('q')
            || $request->query->has('page')
            || (bool) array_filter($advancedFields, fn($f) => $request->query->has($f));

        if ($hasExplicitState) {
            $q = trim($request->query->getString('q'));
            foreach ($advancedFields as $field) {
                $val = trim($request->query->getString($field));
                if ($val !== '') {
                    $criteria[$field] = $val;
                }
            }
        }

        $isAdvanced = !empty($criteria);

        if ($isAdvanced) {
            ['records' => $records, 'total' => $total] =
                $recordRepo->advancedSearchPaginated($domain, $criteria, $page, self::PER_PAGE);
        } else {
            ['records' => $records, 'total' => $total] =
                $recordRepo->searchPaginated($domain, $q, $page, self::PER_PAGE);
        }

        $baseParams = ['id' => $domain->getId()];
        if ($q !== '') {
            $baseParams['q'] = $q;
        }
        foreach ($criteria as $k => $v) {
            $baseParams[$k] = $v;
        }

        return $this->render('domain/show.html.twig', [
            'domain'     => $domain,
            'q'          => $q,
            'criteria'   => $criteria,
            'isAdvanced' => $isAdvanced,
            'records'    => $records,
            'views'      => $viewRepo->findBy([], ['name' => 'ASC']),
            'recordTypes' => RecordType::cases(),
            'pag'        => [
                'page'        => $page,
                'pages'       => max(1, (int) ceil($total / self::PER_PAGE)),
                'per_page'    => self::PER_PAGE,
                'total'       => $total,
                'link_params' => $baseParams,
                'page_param'  => 'page',
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
