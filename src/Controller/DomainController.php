<?php

namespace App\Controller;

use App\Entity\Domain;
use App\Entity\DomainAlias;
use App\Enum\RecordType;
use App\Form\DomainType;
use App\Repository\DnsServerRepository;
use App\Repository\DnsViewRepository;
use App\Repository\DomainAliasRepository;
use App\Repository\DomainRecordRepository;
use App\Repository\DomainRepository;
use App\Service\KskRolloverService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

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

    #[Route('/{id}/ds-record', name: 'domain_ds_record', methods: ['GET'])]
    public function dsRecord(
        Domain $domain,
        DnsServerRepository $serverRepo,
        KskRolloverService $kskService,
    ): JsonResponse {
        if (!$domain->getDnssecPolicy()) {
            return $this->json(['error' => 'This domain does not have a DNSSEC policy.'], 400);
        }

        $domainViewIds = array_map(fn($v) => $v->getId(), $domain->getViews()->toArray());
        if (empty($domainViewIds)) {
            return $this->json(['error' => 'This domain is not assigned to any DNS views.'], 400);
        }

        $servers = array_filter(
            $serverRepo->findAll(),
            fn($s) => $s->isPrimary()
                && $s->getKeyDirectory()
                && !empty(array_intersect(
                    array_map(fn($v) => $v->getId(), $s->getViews()->toArray()),
                    $domainViewIds
                ))
        );

        if (empty($servers)) {
            return $this->json(['error' => 'No primary DNS server with a configured key directory serves this domain.'], 400);
        }

        $allDs  = [];
        $errors = [];
        foreach ($servers as $server) {
            try {
                foreach ($kskService->fetchCurrentDsRecords($domain, $server) as $record) {
                    $allDs[] = $record;
                }
            } catch (\Throwable $e) {
                $errors[] = $server->getName() . ': ' . $e->getMessage();
            }
        }

        $allDs = array_values(array_unique($allDs));

        if (empty($allDs) && !empty($errors)) {
            return $this->json(['error' => implode('; ', $errors)], 500);
        }

        return $this->json([
            'ds_records' => $allDs,
            'errors'     => $errors,
        ]);
    }

    #[Route('/{id}/keys', name: 'domain_keys', methods: ['GET'])]
    public function keys(Domain $domain, DnsServerRepository $serverRepo, KskRolloverService $kskService): JsonResponse
    {
        if (!$domain->getDnssecPolicy()) {
            return $this->json(['error' => 'This domain does not have a DNSSEC policy.'], 400);
        }

        $domainViewIds = array_map(fn($v) => $v->getId(), $domain->getViews()->toArray());
        if (empty($domainViewIds)) {
            return $this->json(['error' => 'This domain is not assigned to any DNS views.'], 400);
        }

        $servers = array_filter(
            $serverRepo->findAll(),
            fn($s) => $s->isPrimary()
                && $s->getKeyDirectory()
                && !empty(array_intersect(
                    array_map(fn($v) => $v->getId(), $s->getViews()->toArray()),
                    $domainViewIds
                ))
        );

        if (empty($servers)) {
            return $this->json(['error' => 'No primary DNS server with a configured key directory serves this domain.'], 400);
        }

        $seenTags = [];
        $allKeys  = [];
        $errors   = [];
        foreach ($servers as $server) {
            try {
                foreach ($kskService->fetchAllKeyInfo($domain, $server) as $key) {
                    if (!in_array($key['key_tag'], $seenTags, true)) {
                        $seenTags[] = $key['key_tag'];
                        $allKeys[]  = $key;
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = $server->getName() . ': ' . $e->getMessage();
            }
        }

        if (empty($allKeys) && !empty($errors)) {
            return $this->json(['error' => implode('; ', $errors)], 500);
        }

        usort($allKeys, fn($a, $b) =>
            $b['flags'] <=> $a['flags']         // KSK (257) before ZSK (256)
            ?: $a['algorithm'] <=> $b['algorithm']
        );

        return $this->json([
            'keys'   => $allKeys,
            'errors' => $errors,
        ]);
    }

    #[Route('/{id}/edit', name: 'domain_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Domain $domain, EntityManagerInterface $em): Response
    {
        $originalPolicy = $domain->getDnssecPolicy();
        $form = $this->createForm(DomainType::class, $domain);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($originalPolicy !== null) {
                $domain->setDnssecPolicy($originalPolicy);
            }
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

    #[Route('/{id}/aliases/add', name: 'domain_alias_add', methods: ['POST'])]
    public function addAlias(
        Request $request,
        Domain $domain,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
    ): Response {
        if (!$this->isCsrfTokenValid('alias_add_' . $domain->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('domain_show', ['id' => $domain->getId()]);
        }

        $name  = trim((string) $request->request->get('alias_name', ''));
        $alias = (new DomainAlias())->setName($name)->setDomain($domain);

        $errors = $validator->validate($alias);
        if (count($errors) > 0) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error->getMessage());
            }
            return $this->redirectToRoute('domain_show', ['id' => $domain->getId()]);
        }

        $domain->addAlias($alias);
        $em->persist($alias);
        $em->flush();
        $this->addFlash('success', 'Alias "' . $name . '" added.');

        return $this->redirectToRoute('domain_show', ['id' => $domain->getId()]);
    }

    #[Route('/{id}/aliases/{aliasId}/delete', name: 'domain_alias_delete', methods: ['POST'])]
    public function deleteAlias(
        Request $request,
        Domain $domain,
        int $aliasId,
        EntityManagerInterface $em,
        DomainAliasRepository $aliasRepo,
    ): Response {
        if (!$this->isCsrfTokenValid('alias_delete_' . $aliasId, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('domain_show', ['id' => $domain->getId()]);
        }

        $alias = $aliasRepo->find($aliasId);
        if ($alias !== null && $alias->getDomain()->getId() === $domain->getId()) {
            $em->remove($alias);
            $em->flush();
            $this->addFlash('success', 'Alias "' . $alias->getName() . '" removed.');
        }

        return $this->redirectToRoute('domain_show', ['id' => $domain->getId()]);
    }
}
