<?php

namespace App\Service;

use App\Entity\Host;
use App\Entity\NetworkInterface;
use App\Entity\SnipeItAssetLink;
use App\Entity\SnipeItServer;
use App\Entity\Tag;
use App\Repository\NetworkInterfaceRepository;
use App\Repository\SnipeItAssetLinkRepository;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;

class SnipeItSyncService
{
    private const TAG_NAME = 'snipeit';
    private const FETCH_LIMIT = 100;

    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly SnipeItAssetLinkRepository  $linkRepo,
        private readonly NetworkInterfaceRepository  $ifaceRepo,
        private readonly TagRepository               $tagRepo,
    ) {}

    /**
     * Pulls all active Snipe-IT assets that have configured MAC custom fields,
     * creates or updates the corresponding DashDDI hosts, and removes hosts
     * whose assets have been deleted or archived.
     *
     * @return array{created: int, updated: int, deleted: int, skipped: int, errors: string[]}
     */
    public function syncFromServer(SnipeItServer $server): array
    {
        $result = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => []];

        // Cache scalar values before any em->clear() detaches $server
        $serverId      = $server->getId();
        $macFieldNames = $server->getMacCustomFieldNames();

        $snipeTag = $this->ensureTag(self::TAG_NAME);
        $this->em->flush(); // persist new tag before first clear

        $activeAssetIds  = [];
        $adoptedHostIds  = []; // host IDs adopted this session; checked before DB flush makes the link visible
        $offset = 0;
        $total  = PHP_INT_MAX;

        while ($offset < $total) {
            $response = $this->request($server, 'GET', '/api/v1/hardware', null, [
                'limit'  => self::FETCH_LIMIT,
                'offset' => $offset,
                'sort'   => 'id',
                'order'  => 'asc',
            ]);

            if (!$response['success']) {
                throw new \RuntimeException('Snipe-IT API error: ' . $response['error']);
            }

            $data  = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            $rows  = $data['rows'] ?? [];
            $total = (int) ($data['total'] ?? 0);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $asset) {
                $assetId = (int) ($asset['id'] ?? 0);
                if ($assetId === 0) {
                    continue;
                }

                if ($this->isArchived($asset)) {
                    continue;
                }

                $macs = $this->extractMacs($asset, $macFieldNames);
                if (empty($macs)) {
                    $result['skipped']++;
                    continue;
                }

                $activeAssetIds[] = $assetId;
                $assetName   = trim((string) ($asset['name'] ?? ''));
                if ($assetName === '') {
                    $assetName = trim((string) ($asset['asset_tag'] ?? ''));
                }
                if ($assetName === '') {
                    $assetName = 'Asset ' . $assetId;
                }
                $assetTagStr = trim((string) ($asset['asset_tag'] ?? ''));

                $link = $this->linkRepo->findByServerAndAssetId($server, $assetId);

                try {
                    if ($link !== null) {
                        $kept = $this->updateHost($link, $assetName, $assetTagStr, $macs, $result['errors']);
                        if (!$kept) {
                            $this->em->remove($link);
                            $result['deleted']++;
                            $activeAssetIds = array_diff($activeAssetIds, [$assetId]);
                        } else {
                            $link->setSyncedAt(new \DateTimeImmutable());
                            $result['updated']++;
                        }
                    } else {
                        // No existing link — check if any of these MACs already belong to a DashDDI host
                        $normalizedMacs = array_values(array_unique(array_map([$this, 'normalizeMac'], $macs)));
                        $conflictHosts  = [];
                        foreach ($normalizedMacs as $mac) {
                            $iface = $this->ifaceRepo->findOneBy(['macAddress' => $mac]);
                            if ($iface !== null) {
                                $h = $iface->getHost();
                                $conflictHosts[$h->getId()] = $h;
                            }
                        }

                        $adopted = false;
                        if (count($conflictHosts) > 1) {
                            // MACs span multiple DashDDI hosts — merge if all are unlinked
                            // (typical cause: dual-NIC machines with one entry per interface in old IPAM)
                            $hasLinked = false;
                            foreach ($conflictHosts as $hId => $h) {
                                if ($h->getSnipeItAssetLink() !== null || isset($adoptedHostIds[$hId])) {
                                    $hasLinked = true;
                                    break;
                                }
                            }
                            if ($hasLinked) {
                                $result['errors'][] = sprintf(
                                    'Asset %d (%s): MACs span multiple already-linked hosts — skipped.',
                                    $assetId, $assetName
                                );
                                $result['skipped']++;
                                continue;
                            }
                            // All unlinked — pick the host with the most interfaces as primary
                            $hosts = array_values($conflictHosts);
                            usort($hosts, fn($a, $b) => $b->getInterfaces()->count() <=> $a->getInterfaces()->count());
                            $host = $hosts[0];
                            $this->mergeHosts($host, array_slice($hosts, 1));
                            $this->adoptHost($host, $normalizedMacs, $snipeTag);
                            $adoptedHostIds[$host->getId()] = true;
                            $adopted = true;
                        } elseif (count($conflictHosts) === 1) {
                            $host   = array_values($conflictHosts)[0];
                            $hostId = $host->getId();
                            // Skip if already linked in the DB or adopted earlier in this session
                            // (two Snipe assets sharing the same MAC both try to adopt the same host)
                            if ($host->getSnipeItAssetLink() !== null || isset($adoptedHostIds[$hostId])) {
                                $result['skipped']++;
                                continue;
                            }
                            $adoptedHostIds[$hostId] = true;
                            // Adopt the pre-existing DashDDI host instead of creating a new one
                            $adopted = true;
                            $this->adoptHost($host, $normalizedMacs, $snipeTag);
                        } else {
                            $host = $this->createHost($assetName, $macs, $snipeTag, $result['errors']);
                            if ($host === null) {
                                $result['skipped']++;
                                continue;
                            }
                        }

                        $link = new SnipeItAssetLink();
                        $link->setServer($server);
                        $link->setHost($host);
                        $link->setSnipeAssetId($assetId);
                        $link->setSnipeAssetTag($assetTagStr);
                        $link->setSnipeAssetName($assetName);
                        $link->setAdopted($adopted);
                        $this->em->persist($link);
                        $result['created']++;
                    }
                } catch (\Throwable $e) {
                    $result['errors'][] = sprintf('Asset %d (%s): %s', $assetId, $assetName, $e->getMessage());
                }
            }

            // Flush and clear after each page to prevent Doctrine's identity map from
            // accumulating thousands of hydrated entities and exhausting PHP memory.
            $this->em->flush();
            $this->em->clear();

            // Re-acquire entities detached by clear()
            $server   = $this->em->find(SnipeItServer::class, $serverId);
            $snipeTag = $this->ensureTag(self::TAG_NAME);

            $offset += self::FETCH_LIMIT;
        }

        // Remove or unlink hosts whose Snipe-IT assets are no longer active
        $snipeTag      = $this->ensureTag(self::TAG_NAME);
        $existingLinks = $this->linkRepo->findByServer($server);
        foreach ($existingLinks as $link) {
            if (!in_array($link->getSnipeAssetId(), $activeAssetIds, true)) {
                if ($link->isAdopted()) {
                    // Preserve the pre-existing host; just remove the link and the snipeit tag
                    $link->getHost()->removeTag($snipeTag);
                    $this->em->createQuery('DELETE FROM App\Entity\SnipeItAssetLink l WHERE l.id = :id')
                        ->setParameter('id', $link->getId())
                        ->execute();
                    $this->em->detach($link);
                } else {
                    $this->em->remove($link); // cascade also removes the sync-created host
                }
                $result['deleted']++;
            }
        }

        $server->setLastSyncAt(new \DateTimeImmutable());
        $this->em->flush();

        return $result;
    }

    /**
     * Moves all interfaces from each host in $others into $primary, then deletes the now-empty hosts.
     * Uses DBAL directly to avoid Doctrine's orphan-removal scheduling interfering with the UPDATE
     * before the DELETE. host_tag rows are cleaned up by the DB-level ON DELETE CASCADE on host.
     */
    private function mergeHosts(Host $primary, array $others): void
    {
        $conn = $this->em->getConnection();
        foreach ($others as $other) {
            $conn->executeStatement(
                'UPDATE network_interface SET host_id = ? WHERE host_id = ?',
                [$primary->getId(), $other->getId()]
            );
            $conn->executeStatement('DELETE FROM host WHERE id = ?', [$other->getId()]);
            $this->em->detach($other);
        }
        // Reload primary so Doctrine's identity map reflects the moved interfaces
        $this->em->refresh($primary);
    }

    /**
     * Adds the snipeit tag and any missing MAC interfaces to a pre-existing host.
     * Does not change the host's name.
     */
    private function adoptHost(Host $host, array $normalizedMacs, Tag $snipeTag): void
    {
        $host->addTag($snipeTag);
        $existingMacs = array_map(
            fn(NetworkInterface $i) => $i->getMacAddress(),
            $host->getInterfaces()->toArray()
        );
        foreach ($normalizedMacs as $mac) {
            if (in_array($mac, $existingMacs, true)) {
                continue;
            }
            // Safety check: only add if not already assigned to a different host
            $conflict = $this->ifaceRepo->findOneBy(['macAddress' => $mac]);
            if ($conflict !== null && $conflict->getHost()?->getId() !== $host->getId()) {
                continue;
            }
            $iface = new NetworkInterface();
            $iface->setMacAddress($mac);
            $iface->setHost($host);
            $this->em->persist($iface);
        }
    }

    /** Returns null if no interfaces could be created (all MACs already assigned elsewhere). */
    private function createHost(string $name, array $macs, Tag $snipeTag, array &$errors): ?Host
    {
        $host = new Host();
        $host->setName($name);
        $host->addTag($snipeTag);

        $added = 0;
        foreach ($macs as $mac) {
            $existing = $this->ifaceRepo->findOneBy(['macAddress' => $this->normalizeMac($mac)]);
            if ($existing !== null) {
                $errors[] = sprintf('MAC %s already assigned to another host — skipped for asset "%s".', $mac, $name);
                continue;
            }
            $iface = new NetworkInterface();
            $iface->setMacAddress($mac);
            $iface->setHost($host);
            $this->em->persist($iface);
            $added++;
        }

        if ($added === 0) {
            return null;
        }

        $this->em->persist($host);
        return $host;
    }

    /**
     * Returns false (and removes the host) if it ends up with no interfaces after the update.
     * Preserves the DashDDI host name when it has been customised or adopted (differs from the
     * previously stored Snipe name); otherwise updates it to track renames in Snipe-IT.
     */
    private function updateHost(SnipeItAssetLink $link, string $name, string $assetTagStr, array $macs, array &$errors): bool
    {
        $host = $link->getHost();

        // Only sync the host name when it still matches the Snipe name from the last sync.
        // If they've diverged (adopted host or manual rename), leave the DashDDI name alone.
        if ($host->getName() === $link->getSnipeAssetName()) {
            $host->setName($name);
        }
        $link->setSnipeAssetName($name);
        $link->setSnipeAssetTag($assetTagStr);

        $normalizedMacs = array_map([$this, 'normalizeMac'], $macs);

        // Remove interfaces whose MACs are no longer in the asset
        foreach ($host->getInterfaces() as $iface) {
            if (!in_array($iface->getMacAddress(), $normalizedMacs, true)) {
                $host->removeInterface($iface);
                $this->em->remove($iface);
            }
        }

        // Add new interfaces for MACs not yet on this host
        $existingMacs = array_map(
            fn(NetworkInterface $i) => $i->getMacAddress(),
            $host->getInterfaces()->toArray()
        );
        $conn = $this->em->getConnection();
        foreach ($normalizedMacs as $mac) {
            if (in_array($mac, $existingMacs, true)) {
                continue;
            }
            $conflict = $this->ifaceRepo->findOneBy(['macAddress' => $mac]);
            if ($conflict !== null && $conflict->getHost()?->getId() !== $host->getId()) {
                $conflictHost = $conflict->getHost();
                if ($conflictHost?->getSnipeItAssetLink() !== null) {
                    $errors[] = sprintf('MAC %s already linked via another Snipe asset — skipped for "%s".', $mac, $name);
                    continue;
                }
                // Unlinked host — move this interface to the Snipe-linked host via DBAL to avoid
                // Doctrine's orphan-removal race with the host deletion on flush.
                $conn->executeStatement(
                    'UPDATE network_interface SET host_id = ? WHERE id = ?',
                    [$host->getId(), $conflict->getId()]
                );
                $remaining = (int) $conn->fetchOne(
                    'SELECT COUNT(*) FROM network_interface WHERE host_id = ?',
                    [$conflictHost->getId()]
                );
                if ($remaining === 0) {
                    $conn->executeStatement('DELETE FROM host WHERE id = ?', [$conflictHost->getId()]);
                }
                $this->em->detach($conflict);
                $this->em->detach($conflictHost);
                // Record the MAC so the loop below doesn't try to create a duplicate interface
                $existingMacs[] = $mac;
                continue;
            }
            $iface = new NetworkInterface();
            $iface->setMacAddress($mac);
            $iface->setHost($host);
            $this->em->persist($iface);
        }

        if ($host->getInterfaces()->isEmpty()) {
            $this->em->remove($host);
            return false;
        }

        return true;
    }

    private function ensureTag(string $tagName): Tag
    {
        $tag = $this->tagRepo->findOneBy(['name' => $tagName]);
        if ($tag === null) {
            $tag = new Tag();
            $tag->setName($tagName);
            $this->em->persist($tag);
        }
        return $tag;
    }

    private function isArchived(array $asset): bool
    {
        if (!empty($asset['deleted_at'])) {
            return true;
        }
        $statusMeta = $asset['status_label']['status_meta'] ?? '';
        $statusType = $asset['status_label']['status_type'] ?? '';
        return $statusMeta === 'archived' || $statusType === 'archived';
    }

    private function extractMacs(array $asset, array $fieldNames): array
    {
        $macs = [];
        $customFields = $asset['custom_fields'] ?? [];

        foreach ($fieldNames as $fieldName) {
            $value = trim((string) ($customFields[$fieldName]['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            // A single field may contain multiple MACs separated by newlines, semicolons, or commas
            foreach (preg_split('/[\s,;]+/', $value) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate !== '' && $this->isValidMac($candidate)) {
                    $macs[] = $candidate;
                }
            }
        }

        return array_values(array_unique($macs));
    }

    private function isValidMac(string $mac): bool
    {
        return (bool) preg_match('/^([0-9a-fA-F]{2}[:\-.]){5}[0-9a-fA-F]{2}$/', $mac);
    }

    private function normalizeMac(string $mac): string
    {
        $hex = preg_replace('/[^0-9a-fA-F]/', '', $mac);
        if (strlen($hex) !== 12) {
            return strtolower($mac);
        }
        return implode(':', str_split(strtolower($hex), 2));
    }

    private function request(
        SnipeItServer $server,
        string $method,
        string $path,
        ?array $body,
        array $query = [],
    ): array {
        $url = $server->getApiUrl() . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $headers = "Content-Type: application/json\r\nAccept: application/json\r\n";
        if ($server->getApiKey()) {
            $headers .= 'Authorization: Bearer ' . $server->getApiKey() . "\r\n";
        }

        $httpOptions = [
            'method'        => $method,
            'header'        => $headers,
            'timeout'       => 30,
            'ignore_errors' => true,
        ];

        if ($body !== null) {
            $httpOptions['content'] = json_encode($body);
        }

        $sslOptions = [];
        if (!$server->isVerifyTls()) {
            $sslOptions['verify_peer']      = false;
            $sslOptions['verify_peer_name'] = false;
        }

        $context  = stream_context_create(['http' => $httpOptions, 'ssl' => $sslOptions]);
        error_clear_last();
        $raw      = @file_get_contents($url, false, $context);
        $httpMeta = $http_response_header ?? [];

        $status = 0;
        foreach ($httpMeta as $header) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $header, $m)) {
                $status = (int) $m[1];
                break;
            }
        }

        if ($raw === false || ($status >= 400)) {
            if ($raw === false) {
                $phpError = error_get_last();
                $detail   = $phpError ? preg_replace('/^.*?: /', '', $phpError['message']) : 'Connection failed';
                $error    = 'Connection failed: ' . $detail;
            } else {
                $error = 'HTTP ' . $status;
            }
            return [
                'success' => false,
                'error'   => $error,
                'body'    => $raw ?: '',
                'status'  => $status,
            ];
        }

        return ['success' => true, 'error' => '', 'body' => $raw, 'status' => $status];
    }
}
