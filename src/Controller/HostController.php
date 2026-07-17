<?php

namespace App\Controller;

use App\Entity\Host;
use App\Form\HostType;
use App\Repository\BuildingRepository;
use App\Repository\HostRepository;
use App\Repository\SubnetRepository;
use App\Repository\TagRepository;
use App\Repository\VirtualIpRepository;
use App\Entity\UserPreference;
use App\Repository\UserPreferenceRepository;
use App\Service\IpAddressManager;
use App\Service\ReservedTagPrefixService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/hosts')]
class HostController extends AbstractController
{
    public function __construct(
        private readonly IpAddressManager $ipManager,
        private readonly ReservedTagPrefixService $reservedPrefixes,
    ) {}

    private const PER_PAGE = 50;

    #[Route('', name: 'host_index', methods: ['GET'])]
    public function index(Request $request, HostRepository $repo, SubnetRepository $subnetRepo, BuildingRepository $buildingRepo, TagRepository $tagRepo, UserPreferenceRepository $prefRepo, VirtualIpRepository $vipRepo, EntityManagerInterface $em): Response
    {
        $user       = $this->getUser();
        $pref       = $user ? $prefRepo->findByIdentifier($user->getUserIdentifier()) : null;
        $page       = max(1, $request->query->getInt('page', 1));
        $reset      = $request->query->getBoolean('reset');

        $validSorts = ['name', 'updated', 'ip'];
        $validDirs  = ['asc', 'desc'];
        $sort = $request->query->getString('sort', 'name');
        $dir  = $request->query->getString('dir', 'asc');
        if (!in_array($sort, $validSorts, true)) { $sort = 'name'; }
        if (!in_array($dir, $validDirs, true))   { $dir  = 'asc'; }

        $query      = '';
        $orGroups   = [];
        $isAdvanced = false;
        $needsFlush = false;

        $hasExplicitState = $request->query->has('q') || $request->query->has('page');

        if ($reset) {
            if ($user && $pref) {
                $pref->setHostSearch(null);
                $needsFlush = true;
            }
        } elseif ($hasExplicitState) {
            $query = trim($request->query->getString('q'));
            if ($user) {
                if (!$pref) {
                    $pref = new UserPreference($user->getUserIdentifier());
                    $em->persist($pref);
                }
                $pref->setHostSearch($query !== '' ? ['q' => $query] : null);
                $needsFlush = true;
            }
        } else {
            $saved = $pref?->getHostSearch() ?? [];
            if (isset($saved['q'])) {
                $query = $saved['q'];
            } elseif (!empty($saved)) {
                // Backward compat: old format stored individual field keys
                $oldFields = ['name', 'building', 'room', 'subnet', 'ip', 'mac', 'dns', 'tag', 'dhcp_mismatch'];
                $parts = [];
                foreach ($oldFields as $f) {
                    if (!empty($saved[$f])) {
                        $parts[] = "$f:{$saved[$f]}";
                    }
                }
                $query = implode(' AND ', $parts);
            }
        }

        $orGroups   = self::parseStructuredQuery($query);
        $isAdvanced = !empty($orGroups);

        if ($needsFlush) {
            $em->flush();
        }

        // Derive showDeleted for template display (controls buttons/restore UI)
        $showDeleted = false;
        foreach ($orGroups as $conditions) {
            foreach ($conditions as [$field, $value, $negate]) {
                if ($field === 'deleted' && $value === '1' && !$negate) {
                    $showDeleted = true;
                }
            }
        }

        if ($isAdvanced) {
            ['hosts' => $hosts, 'total' => $total] = $repo->structuredSearchPaginated($orGroups, $page, self::PER_PAGE, $sort, $dir);
        } elseif ($query !== '') {
            ['hosts' => $hosts, 'total' => $total] = $repo->searchPaginated($query, $page, self::PER_PAGE, $sort, $dir);
        } else {
            ['hosts' => $hosts, 'total' => $total] = $repo->findAllPaginated($page, self::PER_PAGE, $sort, $dir);
        }

        $hostViewMode = $pref?->getHostViewMode() ?? 'host';

        $ifaceIds = [];
        foreach ($hosts as $host) {
            foreach ($host->getInterfaces() as $iface) {
                $ifaceIds[] = $iface->getId();
            }
        }

        $hasCustomSort = ($sort !== 'name' || $dir !== 'asc');
        $linkParams = array_filter([
            'q'    => $query ?: null,
            'sort' => $hasCustomSort ? $sort : null,
            'dir'  => $hasCustomSort ? $dir : null,
        ]);

        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));

        $containerSubnetIds = array_map(fn($s) => $s->getId(), $subnetRepo->findBy(['isContainer' => true]));

        return $this->render('host/index.html.twig', [
            'hosts'              => $hosts,
            'query'              => $query,
            'isAdvanced'         => $isAdvanced,
            'showDeleted'        => $showDeleted,
            'sort'               => $sort,
            'dir'                => $dir,
            'subnets'            => $subnetRepo->findBy([], ['name' => 'ASC']),
            'buildings'          => $buildingRepo->findBy([], ['name' => 'ASC']),
            'tags'               => $tagRepo->findBy([], ['name' => 'ASC']),
            'hostViewMode'       => $hostViewMode,
            'vip_map'            => $vipRepo->findMapByInterfaceIds($ifaceIds),
            'containerSubnetIds' => $containerSubnetIds,
            'pagination'         => [
                'page'        => $page,
                'per_page'    => self::PER_PAGE,
                'total'       => $total,
                'pages'       => $totalPages,
                'link_params' => $linkParams,
            ],
        ]);
    }

    /**
     * Parse a structured query string into OR-groups of AND-conditions.
     * Each condition: [field, value, negate].
     * Returns [] for plain-text queries with no known field:value tokens.
     *
     * @return array<array<array{string, string, bool}>>
     */
    private static function parseStructuredQuery(string $q): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }

        $known = ['name', 'building', 'room', 'subnet', 'ip', 'mac', 'dns', 'tag',
                  'dhcp_mismatch', 'last_dhcp', 'last_auth', 'switch_ip', 'switch_port', 'deleted'];

        $fieldPattern = implode('|', $known);
        $orParts      = self::splitRespectingParens($q, ' OR ');
        $orGroups     = [];

        foreach ($orParts as $orPart) {
            $orPart = trim($orPart);
            if (str_starts_with($orPart, '(') && str_ends_with($orPart, ')')) {
                $orPart = trim(substr($orPart, 1, -1));
            }

            $andConditions = [];
            foreach (explode(' AND ', $orPart) as $token) {
                $token = trim($token);
                if (!preg_match('/^(' . $fieldPattern . '):(\"(?:[^\"\\\\]|\\\\.)*\"|[^\s]+)$/', $token, $m)) {
                    continue;
                }
                $raw = $m[2];
                if (str_starts_with($raw, '"') && str_ends_with($raw, '"')) {
                    $raw = stripslashes(substr($raw, 1, -1));
                }
                $negate = false;
                if (str_starts_with($raw, '!')) {
                    $negate = true;
                    $raw    = substr($raw, 1);
                }
                if ($raw !== '') {
                    $andConditions[] = [$m[1], $raw, $negate];
                }
            }

            if (!empty($andConditions)) {
                $orGroups[] = $andConditions;
            }
        }

        return $orGroups;
    }

    /** Split $str on $sep, ignoring occurrences inside parentheses. */
    private static function splitRespectingParens(string $str, string $sep): array
    {
        $parts   = [];
        $depth   = 0;
        $current = '';
        $sepLen  = strlen($sep);
        $len     = strlen($str);

        for ($i = 0; $i < $len; $i++) {
            $c = $str[$i];
            if ($c === '(') {
                $depth++;
                $current .= $c;
            } elseif ($c === ')') {
                $depth--;
                $current .= $c;
            } elseif ($depth === 0 && substr($str, $i, $sepLen) === $sep) {
                $parts[] = $current;
                $current = '';
                $i += $sepLen - 1;
            } else {
                $current .= $c;
            }
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    #[Route('/new', name: 'host_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, SubnetRepository $subnetRepo, UserPreferenceRepository $prefRepo): Response
    {
        $user          = $this->getUser();
        $pref          = $user ? $prefRepo->findByIdentifier($user->getUserIdentifier()) : null;
        $subnetChoices = $subnetRepo->buildGroupedChoices($pref?->getSubnetSearch());

        $host = new Host();
        $form = $this->createForm(HostType::class, $host, [
            'embed_interface' => true,
            'subnet_choices'  => $subnetChoices,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $ifaceForm = $form->get('interface');
            $interface = $ifaceForm->getData();
            $subnet    = $interface->getSubnet();

            $errors = [];
            if ($ifaceForm->get('ipv4Assignment')->getData() === 'select' && $subnet) {
                $ip = trim((string) $ifaceForm->get('ipv4AddressInput')->getData());
                if ($ip !== '') {
                    $err = $this->ipManager->validateSpecifiedIpv4($ip, $subnet);
                    if ($err) {
                        $errors[] = $err;
                    }
                }
            }
            if ($ifaceForm->get('ipv6Assignment')->getData() === 'select' && $subnet) {
                $ip = trim((string) $ifaceForm->get('ipv6AddressInput')->getData());
                if ($ip !== '') {
                    $err = $this->ipManager->validateSpecifiedIpv6($ip, $subnet);
                    if ($err) {
                        $errors[] = $err;
                    }
                }
            }

            if ($errors) {
                foreach ($errors as $error) {
                    $this->addFlash('danger', $error);
                }
            } else {
                $interface->setHost($host);
                $this->assignIps($ifaceForm, $interface);

                $em->persist($host);
                $em->persist($interface);
                $em->flush();
                $this->addFlash('success', 'Host "' . $host->getName() . '" created.');
                return $this->redirectToRoute('host_show', ['id' => $host->getId()]);
            }
        }

        return $this->render('host/form.html.twig', [
            'form'            => $form,
            'host'            => $host,
            'title'           => 'New Host',
            'embed_interface' => true,
        ]);
    }

    #[Route('/bulk', name: 'host_bulk', methods: ['POST'])]
    public function bulk(Request $request, HostRepository $repo, TagRepository $tagRepo, EntityManagerInterface $em): JsonResponse
    {
        $data   = json_decode($request->getContent(), true) ?? [];
        $action = $data['action'] ?? '';
        $ids    = array_values(array_filter(array_map('intval', $data['ids'] ?? []), fn($id) => $id > 0));

        if (!$this->isCsrfTokenValid('bulk_hosts', $data['_token'] ?? '')) {
            return $this->json(['error' => 'Invalid CSRF token'], 403);
        }
        if (empty($ids)) {
            return $this->json(['error' => 'No hosts selected'], 400);
        }

        $hosts = $repo->findBy(['id' => $ids]);

        if ($action === 'delete') {
            $count = count($hosts);
            foreach ($hosts as $host) {
                $host->softDeleteWithInterfaces();
            }
            $em->flush();
            return $this->json(['message' => $count . ' host(s) deleted.']);
        }

        if ($action === 'restore') {
            $count = count($hosts);
            foreach ($hosts as $host) {
                $host->restore();
                foreach ($host->getInterfaces() as $iface) {
                    $iface->restore();
                }
            }
            $em->flush();
            return $this->json(['message' => $count . ' host(s) restored.']);
        }

        if ($action === 'add-tag' || $action === 'remove-tag') {
            $tagIds = array_values(array_filter(array_map('intval', (array) ($data['tagIds'] ?? [])), fn($id) => $id > 0));
            $tags   = $tagIds ? $tagRepo->findBy(['id' => $tagIds]) : [];
            if (empty($tags)) {
                return $this->json(['error' => 'No valid tags selected'], 400);
            }
            foreach ($hosts as $host) {
                foreach ($tags as $tag) {
                    $action === 'add-tag' ? $host->addTag($tag) : $host->removeTag($tag);
                }
            }
            $em->flush();
            $verb      = $action === 'add-tag' ? 'added to' : 'removed from';
            $tagNames  = implode(', ', array_map(fn($t) => '"' . $t->getName() . '"', $tags));
            return $this->json(['message' => $tagNames . ' ' . $verb . ' ' . count($hosts) . ' host(s).']);
        }

        if ($action === 'merge') {
            $primaryId = isset($data['primaryId']) ? (int) $data['primaryId'] : 0;
            if (count($hosts) < 2) {
                return $this->json(['error' => 'Select at least 2 hosts to merge'], 400);
            }
            $primary = null;
            $others  = [];
            foreach ($hosts as $host) {
                if ($host->getId() === $primaryId) {
                    $primary = $host;
                } else {
                    $others[] = $host;
                }
            }
            if ($primary === null) {
                return $this->json(['error' => 'Invalid primary host'], 400);
            }

            foreach ($others as $other) {
                foreach ($other->getTags() as $tag) {
                    $primary->addTag($tag);
                }
            }
            $em->flush();

            foreach ($others as $other) {
                // Update only the owning side (setHost) without touching the inverse
                // collection on $other — this avoids triggering orphanRemoval while
                // still letting Doctrine detect the FK change and fire postUpdate for
                // each interface, so the host reassignment appears in the audit log.
                foreach ($other->getInterfaces()->toArray() as $interface) {
                    $interface->setHost($primary);
                }
                $em->flush();

                // Refresh clears $other's now-stale interface collection; cascade:remove
                // then finds nothing to delete, and preRemove fires for the host itself.
                $em->refresh($other);
                $em->remove($other);
                $em->flush();
            }
            $em->refresh($primary);

            $merged = count($others);
            return $this->json(['message' => 'Merged ' . $merged . ' host' . ($merged !== 1 ? 's' : '') . ' into "' . $primary->getName() . '".']);
        }

        return $this->json(['error' => 'Unknown action'], 400);
    }

    #[Route('/{id}', name: 'host_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Host $host, Request $request): Response
    {
        $sessionKey = '_host_token_raw_' . $host->getId();
        $newToken   = $request->getSession()->get($sessionKey);
        if ($newToken) {
            $request->getSession()->remove($sessionKey);
        }

        return $this->render('host/show.html.twig', [
            'host'     => $host,
            'newToken' => $newToken,
        ]);
    }

    #[Route('/{id}/edit', name: 'host_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Host $host, EntityManagerInterface $em): Response
    {
        $reservedTags = $host->getTags()->filter(
            fn($tag) => $this->reservedPrefixes->matchingPrefix($tag->getName()) !== null
        )->toArray();

        $form = $this->createForm(HostType::class, $host);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            foreach ($reservedTags as $tag) {
                $host->addTag($tag);
            }
            $em->flush();
            $this->addFlash('success', 'Host updated.');
            return $this->redirectToRoute('host_show', ['id' => $host->getId()]);
        }

        return $this->render('host/form.html.twig', [
            'form'            => $form,
            'host'            => $host,
            'title'           => 'Edit Host: ' . $host->getName(),
            'embed_interface' => false,
        ]);
    }

    #[Route('/{id}/restore', name: 'host_restore', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function restore(Request $request, Host $host, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('restore_host_' . $host->getId(), $request->request->get('_token'))) {
            $host->restore();
            foreach ($host->getInterfaces() as $iface) {
                $iface->restore();
            }
            $em->flush();
            $this->addFlash('success', 'Host "' . $host->getName() . '" and its interfaces have been restored.');
        }
        return $this->redirectToRoute('host_show', ['id' => $host->getId()]);
    }

    #[Route('/{id}/delete', name: 'host_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Host $host, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_host_' . $host->getId(), $request->request->get('_token'))) {
            $host->softDeleteWithInterfaces();
            $em->flush();
            $this->addFlash('success', 'Host deleted.');
        }
        return $this->redirectToRoute('host_index');
    }

    private function assignIps(\Symfony\Component\Form\FormInterface $ifaceForm, \App\Entity\NetworkInterface $interface): void
    {
        $subnet = $interface->getSubnet();

        $ipv4Mode = $ifaceForm->get('ipv4Assignment')->getData();
        if ($ipv4Mode === 'auto' && $subnet?->getIpv4Cidr()) {
            $ip = $this->ipManager->findNextAvailableIpv4($subnet);
            if ($ip) {
                $this->ipManager->assignIpv4($interface, $ip);
            }
        } elseif ($ipv4Mode === 'select') {
            $ip = trim((string) $ifaceForm->get('ipv4AddressInput')->getData());
            if ($ip !== '' && $subnet) {
                $this->ipManager->assignIpv4($interface, $ip);
            }
        }

        $ipv6Mode = $ifaceForm->get('ipv6Assignment')->getData();
        if ($ipv6Mode === 'auto' && $subnet?->getIpv6Cidr()) {
            $ip = $this->ipManager->findNextAvailableIpv6($subnet, $interface->getMacAddress());
            if ($ip) {
                $this->ipManager->assignIpv6($interface, $ip);
            }
        } elseif ($ipv6Mode === 'auto_v4' && $subnet?->getIpv6Cidr()) {
            $ipv4 = $interface->getIpAddress()?->getAddress();
            if ($ipv4) {
                $ip = $this->ipManager->findIpv6FromIpv4($subnet, $ipv4);
                if ($ip) {
                    $this->ipManager->assignIpv6($interface, $ip);
                }
            }
        } elseif ($ipv6Mode === 'select') {
            $ip = trim((string) $ifaceForm->get('ipv6AddressInput')->getData());
            if ($ip !== '' && $subnet) {
                $this->ipManager->assignIpv6($interface, $ip);
            }
        }
    }
}
