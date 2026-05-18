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

        $snipeTag = $this->ensureTag(self::TAG_NAME);

        $assets = $this->fetchAllAssets($server);
        $activeAssetIds = [];

        foreach ($assets as $asset) {
            $assetId = (int) ($asset['id'] ?? 0);
            if ($assetId === 0) {
                continue;
            }

            if ($this->isArchived($asset)) {
                continue;
            }

            $macs = $this->extractMacs($asset, $server->getMacCustomFieldNames());
            if (empty($macs)) {
                $result['skipped']++;
                continue;
            }

            $activeAssetIds[] = $assetId;
            $assetName = trim((string) ($asset['name'] ?? $asset['asset_tag'] ?? 'Asset ' . $assetId));
            $assetTagStr = trim((string) ($asset['asset_tag'] ?? ''));

            $link = $this->linkRepo->findByServerAndAssetId($server, $assetId);

            try {
                if ($link !== null) {
                    $this->updateHost($link, $assetName, $assetTagStr, $macs, $result['errors']);
                    $link->setSyncedAt(new \DateTimeImmutable());
                    $result['updated']++;
                } else {
                    $host = $this->createHost($assetName, $macs, $snipeTag, $result['errors']);
                    $link = new SnipeItAssetLink();
                    $link->setServer($server);
                    $link->setHost($host);
                    $link->setSnipeAssetId($assetId);
                    $link->setSnipeAssetTag($assetTagStr);
                    $link->setSnipeAssetName($assetName);
                    $this->em->persist($link);
                    $result['created']++;
                }
            } catch (\Throwable $e) {
                $result['errors'][] = sprintf('Asset %d (%s): %s', $assetId, $assetName, $e->getMessage());
            }
        }

        // Remove hosts for assets no longer active in Snipe-IT
        $existingLinks = $this->linkRepo->findByServer($server);
        foreach ($existingLinks as $link) {
            if (!in_array($link->getSnipeAssetId(), $activeAssetIds, true)) {
                $this->em->remove($link);
                $result['deleted']++;
            }
        }

        $server->setLastSyncAt(new \DateTimeImmutable());
        $this->em->flush();

        return $result;
    }

    private function createHost(string $name, array $macs, Tag $snipeTag, array &$errors): Host
    {
        $host = new Host();
        $host->setName($name);
        $host->addTag($snipeTag);
        $this->em->persist($host);

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
        }

        return $host;
    }

    private function updateHost(SnipeItAssetLink $link, string $name, string $assetTagStr, array $macs, array &$errors): void
    {
        $host = $link->getHost();
        $host->setName($name);
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
        foreach ($normalizedMacs as $mac) {
            if (in_array($mac, $existingMacs, true)) {
                continue;
            }
            $conflict = $this->ifaceRepo->findOneBy(['macAddress' => $mac]);
            if ($conflict !== null && $conflict->getHost()?->getId() !== $host->getId()) {
                $errors[] = sprintf('MAC %s already assigned to another host — skipped for asset "%s".', $mac, $name);
                continue;
            }
            $iface = new NetworkInterface();
            $iface->setMacAddress($mac);
            $iface->setHost($host);
            $this->em->persist($iface);
        }
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

    /** Fetch every page of assets from the Snipe-IT API. */
    private function fetchAllAssets(SnipeItServer $server): array
    {
        $assets = [];
        $offset = 0;

        do {
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

            $assets = array_merge($assets, $rows);
            $offset += self::FETCH_LIMIT;
        } while (count($assets) < $total && !empty($rows));

        return $assets;
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
            return [
                'success' => false,
                'error'   => $raw === false ? 'Connection failed' : 'HTTP ' . $status,
                'body'    => $raw ?: '',
                'status'  => $status,
            ];
        }

        return ['success' => true, 'error' => '', 'body' => $raw, 'status' => $status];
    }
}
