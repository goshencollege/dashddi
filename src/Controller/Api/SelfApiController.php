<?php

namespace App\Controller\Api;

use App\Entity\ApiToken;
use App\Entity\Domain;
use App\Entity\DomainRecord;
use App\Entity\Host;
use App\Entity\NetworkInterface;
use App\Entity\VirtualIp;
use App\Enum\RecordType;
use App\Repository\DomainRecordRepository;
use App\Repository\DomainRepository;
use App\Repository\VirtualIpRepository;
use App\Validator\TxtRecordValueValidator;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/self')]
class SelfApiController extends AbstractController
{
    public function __construct(
        private readonly DomainRepository $domainRepository,
        private readonly VirtualIpRepository $virtualIpRepository,
    ) {}

    #[Route('/host', name: 'api_self_host', methods: ['GET'])]
    public function host(Request $request): JsonResponse
    {
        $token = $request->attributes->get('_api_token');
        if (!$token instanceof ApiToken || !$token->isHostScoped()) {
            return $this->json(['error' => 'This endpoint requires a host-scoped token.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json($this->serializeHost($token->getHost()));
    }

    #[Route('/dns-challenge', name: 'api_self_dns_challenge_create', methods: ['POST'])]
    public function createChallenge(
        Request $request,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
    ): JsonResponse {
        $token = $request->attributes->get('_api_token');
        if (!$token instanceof ApiToken || !$token->isHostScoped()) {
            return $this->json(['error' => 'This endpoint requires a host-scoped token.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $fqdn       = trim($data['fqdn'] ?? '');
        $validation = trim($data['validation'] ?? '');

        if ($fqdn === '' || $validation === '') {
            return $this->json(['error' => 'fqdn and validation are required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $match = $this->resolveOwnership($token->getHost(), $fqdn);
        if ($match === null) {
            return $this->json(['error' => 'The requested FQDN does not belong to this host.'], Response::HTTP_FORBIDDEN);
        }

        [$sourceRecord, ] = $match;

        $resolved = $this->resolveChallengeDomain($sourceRecord->getDomain(), $fqdn);
        if ($resolved === null) {
            return $this->json(
                ['error' => 'The domain for this hostname has no public views — ACME validation from the internet is not possible.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        [$targetDomain, $challengeHostname] = $resolved;

        $record = new DomainRecord();
        $record->setHostname($challengeHostname);
        $record->setType(RecordType::TXT);
        $record->setValue(TxtRecordValueValidator::normalizeTxtValue($validation));
        $record->setDomain($targetDomain);
        $record->setNetworkInterface($sourceRecord->getNetworkInterface());
        $record->setVirtualIp($sourceRecord->getVirtualIp());
        // Assign all target domain views so the record appears in every zone file (including
        // the public/external one that Let's Encrypt queries).
        foreach ($targetDomain->getViews() as $view) {
            $record->addView($view);
        }

        $violations = $validator->validate($record);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $v) {
                $errors[] = $v->getMessage();
            }
            return $this->json(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em->persist($record);
        $em->flush();

        return $this->json(['id' => $record->getId(), 'hostname' => $challengeHostname, 'domain' => $targetDomain->getName()], Response::HTTP_CREATED);
    }

    #[Route('/dns-challenge', name: 'api_self_dns_challenge_delete', methods: ['DELETE'])]
    public function deleteChallenge(
        Request $request,
        EntityManagerInterface $em,
        DomainRecordRepository $repo,
    ): JsonResponse {
        $token = $request->attributes->get('_api_token');
        if (!$token instanceof ApiToken || !$token->isHostScoped()) {
            return $this->json(['error' => 'This endpoint requires a host-scoped token.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $fqdn       = trim($data['fqdn'] ?? '');
        $validation = trim($data['validation'] ?? '');

        if ($fqdn === '' || $validation === '') {
            return $this->json(['error' => 'fqdn and validation are required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $match = $this->resolveOwnership($token->getHost(), $fqdn);
        if ($match === null) {
            return $this->json(['error' => 'The requested FQDN does not belong to this host.'], Response::HTTP_FORBIDDEN);
        }

        [$sourceRecord, $owner] = $match;

        // Compute the challenge hostname prefix from the requested FQDN (not the matched
        // record's own hostname — the match may have come from a wildcard record, whose
        // hostname is a different label than the concrete FQDN being certified) so we only
        // match _acme-challenge records for this specific hostname — not other TXT records
        // on the same interface/VIP. We match both the direct form (_acme-challenge.srv) and
        // the parent-domain form (_acme-challenge.srv.internal.*) without re-deriving which
        // domain was chosen at creation time.
        $sourceHostname = $this->relativeHostname($fqdn, $sourceRecord->getDomain());
        $hostnameBase = $sourceHostname === '@'
            ? '_acme-challenge'
            : '_acme-challenge.' . $sourceHostname;

        $normalizedValue = TxtRecordValueValidator::normalizeTxtValue($validation);
        $ownerField = $owner instanceof VirtualIp ? 'r.virtualIp' : 'r.networkInterface';
        $records = $repo->createQueryBuilder('r')
            ->where($ownerField . ' = :owner')
            ->andWhere('r.type = :type')
            ->andWhere('r.value = :value')
            ->andWhere('r.hostname = :exact OR r.hostname LIKE :prefix')
            ->setParameter('owner', $owner)
            ->setParameter('type', RecordType::TXT)
            ->setParameter('value', $normalizedValue)
            ->setParameter('exact', $hostnameBase)
            ->setParameter('prefix', $hostnameBase . '.%')
            ->getQuery()
            ->getResult();

        if (empty($records)) {
            return $this->json(['error' => 'No matching challenge record found.'], Response::HTTP_NOT_FOUND);
        }

        foreach ($records as $record) {
            $em->remove($record);
        }
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/records', name: 'api_self_record_upsert', methods: ['PUT'])]
    public function upsertRecord(
        Request $request,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        DomainRecordRepository $repo,
    ): JsonResponse {
        $token = $request->attributes->get('_api_token');
        if (!$token instanceof ApiToken || !$token->isHostScoped()) {
            return $this->json(['error' => 'This endpoint requires a host-scoped token.'], Response::HTTP_FORBIDDEN);
        }

        $data  = json_decode($request->getContent(), true) ?? [];
        $fqdn  = trim($data['fqdn'] ?? '');
        $type  = trim($data['type'] ?? '');
        $value = trim($data['value'] ?? '');

        if ($fqdn === '' || $type === '' || $value === '') {
            return $this->json(['error' => 'fqdn, type, and value are required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $recordType = RecordType::tryFrom($type);
        if (!in_array($recordType, [RecordType::CAA, RecordType::HTTPS], true)) {
            return $this->json(['error' => 'Only CAA and HTTPS records may be managed through this endpoint.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $match = $this->resolveOwnership($token->getHost(), $fqdn);
        if ($match === null) {
            return $this->json(['error' => 'The requested FQDN does not belong to this host.'], Response::HTTP_FORBIDDEN);
        }
        [$sourceRecord, ] = $match;

        $domain   = $sourceRecord->getDomain();
        $hostname = $this->relativeHostname($fqdn, $domain);

        $existing = $repo->findOneBy(['domain' => $domain, 'hostname' => $hostname, 'type' => $recordType]);
        if ($existing !== null) {
            if ($existing->getValue() === $value) {
                return $this->json(['id' => $existing->getId(), 'action' => 'unchanged'], Response::HTTP_OK);
            }
            $existing->setValue($value);
            $violations = $validator->validate($existing);
            if (count($violations) > 0) {
                $errors = [];
                foreach ($violations as $v) {
                    $errors[] = $v->getMessage();
                }
                return $this->json(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $em->flush();
            return $this->json(['id' => $existing->getId(), 'action' => 'updated'], Response::HTTP_OK);
        }

        $record = new DomainRecord();
        $record->setHostname($hostname);
        $record->setType($recordType);
        $record->setValue($value);
        $record->setDomain($domain);
        $record->setNetworkInterface($sourceRecord->getNetworkInterface());
        $record->setVirtualIp($sourceRecord->getVirtualIp());
        // Inherit the source record's own views — this name's CAA/HTTPS record should be
        // visible everywhere the underlying A/AAAA/wildcard record already is, nothing more.
        foreach ($sourceRecord->getViews() as $view) {
            $record->addView($view);
        }

        $violations = $validator->validate($record);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $v) {
                $errors[] = $v->getMessage();
            }
            return $this->json(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em->persist($record);
        $em->flush();

        return $this->json(['id' => $record->getId(), 'action' => 'created'], Response::HTTP_CREATED);
    }

    /**
     * Find a DomainRecord owned by one of the host's interfaces or by a VIP one of those
     * interfaces is a member of, whose FQDN matches the given string, either exactly or via
     * wildcard coverage. Returns [DomainRecord, owner] or null.
     *
     * @return array{0: DomainRecord, 1: NetworkInterface|VirtualIp}|null
     */
    private function resolveOwnership(Host $host, string $fqdn): ?array
    {
        $interfaces = [];
        foreach ($host->getInterfaces() as $iface) {
            if (!$iface->isDeleted()) {
                $interfaces[] = $iface;
            }
        }
        $vips = $this->vipsForHost($host);

        foreach ($interfaces as $iface) {
            foreach ($iface->getDomainRecords() as $record) {
                if ($record->getFullyQualifiedHostname() === $fqdn) {
                    return [$record, $iface];
                }
            }
        }
        foreach ($vips as $vip) {
            foreach ($vip->getDomainRecords() as $record) {
                if ($record->getFullyQualifiedHostname() === $fqdn) {
                    return [$record, $vip];
                }
            }
        }

        // No exact match — fall back to a wildcard record covering this FQDN, e.g. a
        // "*.example.com" record covers "foo.example.com" as an explicit SAN entry.
        foreach ($interfaces as $iface) {
            foreach ($iface->getDomainRecords() as $record) {
                if ($this->wildcardCovers($record, $fqdn)) {
                    return [$record, $iface];
                }
            }
        }
        foreach ($vips as $vip) {
            foreach ($vip->getDomainRecords() as $record) {
                if ($this->wildcardCovers($record, $fqdn)) {
                    return [$record, $vip];
                }
            }
        }

        return null;
    }

    /**
     * Returns the deduplicated, non-deleted VIPs that any of the host's (non-deleted)
     * interfaces are a member of.
     *
     * @return VirtualIp[]
     */
    private function vipsForHost(Host $host): array
    {
        $ifaceIds = [];
        foreach ($host->getInterfaces() as $iface) {
            if (!$iface->isDeleted()) {
                $ifaceIds[] = $iface->getId();
            }
        }

        $vips = [];
        foreach ($this->virtualIpRepository->findMapByInterfaceIds($ifaceIds) as $vipList) {
            foreach ($vipList as $vip) {
                $vips[$vip->getId()] = $vip;
            }
        }
        return array_values($vips);
    }

    /**
     * True if $record is a wildcard record (hostname "*" or starting with "*.") whose scope
     * covers $fqdn. A wildcard covers exactly one additional label prepended to its own name:
     * "*.example.com" covers "foo.example.com" but not "a.b.example.com", and does not cover
     * itself literally.
     */
    private function wildcardCovers(DomainRecord $record, string $fqdn): bool
    {
        $hostname = $record->getHostname();
        if ($hostname !== '*' && !str_starts_with($hostname, '*.')) {
            return false;
        }

        $wildcardFqdn = $record->getFullyQualifiedHostname(); // e.g. "*.example.com"
        $suffix = substr($wildcardFqdn, 1); // ".example.com"

        if ($fqdn === $wildcardFqdn || !str_ends_with($fqdn, $suffix)) {
            return false;
        }

        $label = substr($fqdn, 0, -strlen($suffix));
        return $label !== '' && !str_contains($label, '.');
    }

    /**
     * Compute $fqdn's hostname label relative to $domain (the inverse of
     * DomainRecord::getFullyQualifiedHostname()). Used to derive the concrete hostname being
     * certified from the request itself rather than from whichever record proved ownership
     * (which may be a wildcard record with a different literal hostname).
     */
    private function relativeHostname(string $fqdn, Domain $domain): string
    {
        $domainName = $domain->getName();
        if ($fqdn === $domainName) {
            return '@';
        }
        $suffix = '.' . $domainName;
        if (str_ends_with($fqdn, $suffix)) {
            return substr($fqdn, 0, -strlen($suffix));
        }
        return $fqdn;
    }

    /**
     * A domain is publicly reachable if it has no view restrictions, or if at least
     * one of its views is marked as public. Used to filter the hostname list so ACME
     * clients only see names they can realistically certify from the internet.
     */
    private function domainHasPublicView(Domain $domain): bool
    {
        if ($domain->getViews()->isEmpty()) {
            return true; // no view restriction → globally accessible
        }
        foreach ($domain->getViews() as $view) {
            if ($view->isPublic()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve the domain and hostname for an ACME challenge record.
     * Returns [$targetDomain, $challengeHostname] or null if no public zone is reachable.
     *
     * @return array{0: Domain, 1: string}|null
     */
    private function resolveChallengeDomain(Domain $sourceDomain, string $fqdn): ?array
    {
        if ($this->domainHasExplicitPublicView($sourceDomain)) {
            $targetDomain = $sourceDomain;
        } else {
            // Walk up to a public parent. This handles both private subdomains (has
            // views but none public) and view-less domains whose DNS is managed
            // externally (e.g. Active Directory), where the challenge must be
            // published via a DashDDI-managed public ancestor instead.
            $targetDomain = $this->findPublicParentDomain($sourceDomain->getName());

            // If no public parent exists, fall back to the source domain only when
            // it has no view restrictions — meaning DashDDI serves it globally.
            if ($targetDomain === null && $sourceDomain->getViews()->isEmpty()) {
                $targetDomain = $sourceDomain;
            }
        }

        if ($targetDomain === null) {
            return null;
        }
        return [$targetDomain, $this->challengeHostnameInParentDomain($fqdn, $targetDomain)];
    }

    /**
     * Returns true only when the domain has at least one view explicitly marked
     * as public. Unlike domainHasPublicView(), view-less domains return false —
     * a domain with no views may not be actively managed by DashDDI for DNS, so
     * placing a challenge record there may not publish it to the internet.
     */
    private function domainHasExplicitPublicView(Domain $domain): bool
    {
        foreach ($domain->getViews() as $view) {
            if ($view->isPublic()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Walk up the DNS label hierarchy to find the nearest ancestor domain that
     * exists in DashDDI and has at least one public view. Used as a fallback when
     * the source record's own domain is internal-only, so the ACME challenge TXT
     * can be published in the public parent zone instead.
     */
    private function findPublicParentDomain(string $domainName): ?Domain
    {
        $parts = explode('.', $domainName);
        // Start at index 1 (skip the source domain itself) and stop before bare TLDs
        for ($i = 1; $i < count($parts) - 1; $i++) {
            $candidate = implode('.', array_slice($parts, $i));
            $domain = $this->domainRepository->findOneBy(['name' => $candidate]);
            if ($domain !== null && $this->domainHasPublicView($domain)) {
                return $domain;
            }
        }
        return null;
    }

    /**
     * Compute the hostname for a challenge TXT record placed in a parent zone.
     * Example: source = "host.internal.example.com", parent = "example.com"
     * → challenge FQDN = "_acme-challenge.host.internal.example.com"
     * → relative hostname = "_acme-challenge.host.internal"
     */
    private function challengeHostnameInParentDomain(string $sourceFqdn, Domain $parentDomain): string
    {
        $challengeFqdn = '_acme-challenge.' . $sourceFqdn;
        return substr($challengeFqdn, 0, -(strlen($parentDomain->getName()) + 1));
    }

    private function serializeHost(Host $host): array
    {
        $reachableCache = []; // domain name → bool, memoizes domainHasPublicView + parent lookup
        $interfaces = [];
        foreach ($host->getInterfaces() as $iface) {
            if ($iface->isDeleted()) {
                continue;
            }
            $interfaces[] = [
                'id'      => $iface->getId(),
                'name'    => $iface->getName(),
                'mac'     => $iface->getMacAddress(),
                'ipv4'    => $iface->getIpAddress()?->getAddress(),
                'ipv6'    => $iface->getIpv6Address()?->getAddress(),
                'records' => $this->serializeReachableRecords($iface->getDomainRecords(), $reachableCache),
            ];
        }

        $vips = [];
        foreach ($this->vipsForHost($host) as $vip) {
            $vips[] = [
                'id'      => $vip->getId(),
                'label'   => $vip->getLabel(),
                'ipv4'    => $vip->getIpAddress()?->getAddress(),
                'ipv6'    => $vip->getIpv6Address()?->getAddress(),
                'records' => $this->serializeReachableRecords($vip->getDomainRecords(), $reachableCache),
            ];
        }

        return [
            'id'         => $host->getId(),
            'name'       => $host->getName(),
            'interfaces' => $interfaces,
            'vips'       => $vips,
        ];
    }

    /**
     * Serializes the DomainRecords of an interface or VIP, dropping any whose domain has no
     * publicly reachable view (directly or via an ancestor domain) — ACME clients should only
     * see names they can realistically certify from the internet.
     *
     * @param  Collection<int, DomainRecord>  $records
     * @param  array<string, bool>            $reachableCache  domain name → reachable, shared across callers
     * @return list<array<string, mixed>>
     */
    private function serializeReachableRecords(Collection $records, array &$reachableCache): array
    {
        $out = [];
        foreach ($records as $record) {
            $domain = $record->getDomain();
            if ($domain === null) {
                continue;
            }
            $domainName = $domain->getName();
            if (!isset($reachableCache[$domainName])) {
                $reachableCache[$domainName] = $this->domainHasPublicView($domain)
                    || $this->findPublicParentDomain($domainName) !== null;
            }
            if (!$reachableCache[$domainName]) {
                continue;
            }
            $out[] = [
                'id'        => $record->getId(),
                'hostname'  => $record->getHostname(),
                'fqdn'      => $record->getFullyQualifiedHostname(),
                'type'      => $record->getType()->value,
                'value'     => $record->getValue(),
                'domain_id' => $domain->getId(),
            ];
        }
        return $out;
    }
}
