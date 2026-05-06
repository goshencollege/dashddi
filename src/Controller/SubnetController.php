<?php

namespace App\Controller;

use App\Entity\AddressBlock;
use App\Entity\Subnet;
use App\Entity\UserPreference;
use App\Enum\BlockType;
use App\Form\SubnetType;
use App\Repository\AppSettingRepository;
use App\Repository\SubnetRepository;
use App\Repository\TagRepository;
use App\Repository\UserPreferenceRepository;
use App\Repository\VrfRepository;
use App\Service\IpAddressManager;
use IPLib\Factory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/subnets')]
class SubnetController extends AbstractController
{
    private const PER_PAGE = 50;

    #[Route('', name: 'subnet_index', methods: ['GET'])]
    public function index(Request $request, SubnetRepository $repo, VrfRepository $vrfRepo, TagRepository $tagRepo, UserPreferenceRepository $prefRepo, EntityManagerInterface $em): Response
    {
        $user  = $this->getUser();
        $pref  = $user ? $prefRepo->findByIdentifier($user->getUserIdentifier()) : null;
        $page  = max(1, $request->query->getInt('page', 1));
        $reset = $request->query->getBoolean('reset');

        $advancedFields = ['name', 'cidr', 'vlan', 'gateway', 'vrf', 'tag'];

        // True when this request explicitly carries search state (not a bare page load).
        $hasExplicitState = $request->query->has('q')
            || $request->query->has('page')
            || (bool) array_filter($advancedFields, fn($f) => $request->query->has($f));

        $query    = '';
        $criteria = [];
        $needsFlush = false;

        if ($reset) {
            // User clicked Clear — wipe the saved search.
            if ($user && $pref) {
                $pref->setSubnetSearch(null);
                $needsFlush = true;
            }
        } elseif ($hasExplicitState) {
            // Search params are in the URL — use them and persist to preferences.
            $query = trim($request->query->getString('q'));
            foreach ($advancedFields as $field) {
                $val = trim($request->query->getString($field));
                if ($val !== '') {
                    $criteria[$field] = $val;
                }
            }
            if ($user) {
                if (!$pref) {
                    $pref = new UserPreference($user->getUserIdentifier());
                    $em->persist($pref);
                }
                $saved = array_filter(['q' => $query] + $criteria, fn($v) => $v !== '');
                $pref->setSubnetSearch($saved ?: null);
                $needsFlush = true;
            }
        } else {
            // Bare page load — restore the last saved search for this user.
            $saved = $pref?->getSubnetSearch() ?? [];
            $query = $saved['q'] ?? '';
            foreach ($advancedFields as $field) {
                if (!empty($saved[$field])) {
                    $criteria[$field] = $saved[$field];
                }
            }
        }

        $isAdvanced  = !empty($criteria);
        $isSearching = $isAdvanced || $query !== '';

        // Resolve view mode independently of search state.
        if ($request->query->has('view')) {
            $view = $request->query->get('view');
            if (!in_array($view, ['name', 'ipv4', 'ipv6'], true)) {
                $view = 'name';
            }
            if ($user) {
                if (!$pref) {
                    $pref = new UserPreference($user->getUserIdentifier());
                    $em->persist($pref);
                }
                $pref->setSubnetViewMode($view);
                $needsFlush = true;
            }
        } elseif ($isSearching) {
            $view = 'name';
        } else {
            $view = $pref?->getSubnetViewMode() ?? 'name';
        }

        if ($needsFlush) {
            $em->flush();
        }

        $subnets    = null;
        $tree       = null;
        $total      = 0;
        $totalPages = 1;

        if ($view === 'name') {
            if ($isAdvanced) {
                ['subnets' => $subnets, 'total' => $total] = $repo->advancedSearchPaginated($criteria, $page, self::PER_PAGE);
            } elseif ($query !== '') {
                ['subnets' => $subnets, 'total' => $total] = $repo->searchPaginated($query, $page, self::PER_PAGE);
            } else {
                ['subnets' => $subnets, 'total' => $total] = $repo->findAllPaginated($page, self::PER_PAGE);
            }
            $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        } else {
            $tree = $repo->buildFlatHierarchy($view);
        }

        $linkParams = array_filter([
            'q'       => $query ?: null,
            'name'    => $criteria['name'] ?? null,
            'cidr'    => $criteria['cidr'] ?? null,
            'vlan'    => $criteria['vlan'] ?? null,
            'gateway' => $criteria['gateway'] ?? null,
            'vrf'     => $criteria['vrf'] ?? null,
            'tag'     => $criteria['tag'] ?? null,
        ]);

        return $this->render('subnet/index.html.twig', [
            'subnets'    => $subnets,
            'tree'       => $tree,
            'view'       => $view,
            'vrfs'       => $vrfRepo->findBy([], ['name' => 'ASC']),
            'tags'       => $tagRepo->findBy([], ['name' => 'ASC']),
            'query'      => $query,
            'criteria'   => $criteria,
            'isAdvanced' => $isAdvanced,
            'pagination' => [
                'page'        => $page,
                'per_page'    => self::PER_PAGE,
                'total'       => $total,
                'pages'       => $totalPages,
                'link_params' => $linkParams,
            ],
        ]);
    }

    #[Route('/new', name: 'subnet_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, AppSettingRepository $settingRepo): Response
    {
        $subnet = new Subnet();
        $defaultDays = $settingRepo->getInstance()?->getDefaultNewSubnetLeaseRetentionDays();
        if ($defaultDays !== null) {
            $subnet->setLeaseRetentionDays($defaultDays);
        }
        $form = $this->createForm(SubnetType::class, $subnet, ['embed_blocks' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($subnet);

            $errors = [];
            foreach (['reservedBlock' => BlockType::Reserved, 'fixedBlock' => BlockType::Fixed] as $field => $type) {
                $block = $form->get($field)->getData();
                if ($block->getStartIp() === '' || $block->getEndIp() === '') {
                    continue;
                }
                $block->setSubnet($subnet);
                $block->setType($type);
                $error = $this->validateBlock($block, $subnet);
                if ($error) {
                    $errors[] = $error;
                } else {
                    $em->persist($block);
                }
            }

            if ($errors) {
                foreach ($errors as $error) {
                    $this->addFlash('danger', $error);
                }
            } else {
                $em->flush();
                $this->addFlash('success', 'Subnet "' . $subnet->getName() . '" created.');
                return $this->redirectToRoute('subnet_show', ['id' => $subnet->getId()]);
            }
        }

        return $this->render('subnet/form.html.twig', [
            'form'         => $form,
            'subnet'       => $subnet,
            'title'        => 'New Subnet',
            'embed_blocks' => true,
        ]);
    }

    #[Route('/{id}', name: 'subnet_show', methods: ['GET'])]
    public function show(Subnet $subnet, IpAddressManager $manager): Response
    {
        return $this->render('subnet/show.html.twig', [
            'subnet'          => $subnet,
            'available_ipv4'  => $subnet->getIpv4Cidr() ? $manager->getAvailableIpv4($subnet, 255) : [],
            'available_ipv6'  => $subnet->getIpv6Cidr() ? $manager->getAvailableIpv6($subnet, 20) : [],
        ]);
    }

    #[Route('/{id}/edit', name: 'subnet_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Subnet $subnet, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(SubnetType::class, $subnet);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Subnet updated.');
            return $this->redirectToRoute('subnet_show', ['id' => $subnet->getId()]);
        }

        return $this->render('subnet/form.html.twig', [
            'form'         => $form,
            'subnet'       => $subnet,
            'title'        => 'Edit Subnet: ' . $subnet->getName(),
            'embed_blocks' => false,
        ]);
    }

    private function validateBlock(AddressBlock $block, Subnet $subnet): ?string
    {
        $start = Factory::parseAddressString($block->getStartIp());
        $end   = Factory::parseAddressString($block->getEndIp());

        if (!$start) return 'Start IP is not a valid IP address.';
        if (!$end)   return 'End IP is not a valid IP address.';

        $version = $start->getAddressType();
        if ($version !== $end->getAddressType()) {
            return 'Start and End IP must be the same protocol.';
        }

        $cidr = $version === 4 ? $subnet->getIpv4Cidr() : $subnet->getIpv6Cidr();
        if (!$cidr) {
            return sprintf('This subnet has no IPv%d CIDR defined.', $version);
        }

        $range = Factory::parseRangeString($cidr);
        if (!$range->contains($start) || !$range->contains($end)) {
            return sprintf('Block IPs must fall within %s.', $cidr);
        }

        if ($start->getComparableString() > $end->getComparableString()) {
            return 'Start IP must be less than or equal to End IP.';
        }

        return null;
    }

    #[Route('/{id}/delete', name: 'subnet_delete', methods: ['POST'])]
    public function delete(Request $request, Subnet $subnet, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_subnet_' . $subnet->getId(), $request->request->get('_token'))) {
            $em->remove($subnet);
            $em->flush();
            $this->addFlash('success', 'Subnet deleted.');
        }
        return $this->redirectToRoute('subnet_index');
    }
}
