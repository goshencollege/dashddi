<?php

namespace App\Service;

use App\Entity\DnssecKskRollover;
use App\Entity\DnsServer;
use App\Entity\Domain;
use App\Entity\Subnet;
use App\Enum\KskRolloverStatus;
use phpseclib3\Net\SFTP;

/**
 * All commands that touch bind-owned key files run under "sudo -u bind".
 * All rndc commands run under "sudo" (as root).
 *
 * Required sudoers entries on each DNS server:
 *   dash ALL=(bind) NOPASSWD: /usr/sbin/dnssec-keygen, /usr/sbin/dnssec-settime, /usr/sbin/dnssec-dsfromkey
 *   dash ALL=(root) NOPASSWD: /usr/sbin/rndc
 */
class KskRolloverService
{
    public function __construct(
        private readonly SshKeyService $sshKeys,
    ) {}

    /**
     * Runs SSH operations for step 1: generate new KSK + rndc loadkeys.
     * Updates the rollover entity but does NOT flush — caller is responsible.
     */
    public function startRollover(DnssecKskRollover $rollover): void
    {
        $sftp      = $this->connect($rollover);
        $zone      = $rollover->getZoneName();
        $keyDir    = rtrim($rollover->getKeyDirectory(), '/');
        $algorithm = $rollover->getAlgorithm();

        $oldFile = $this->findCurrentKsk($sftp, $keyDir, $zone, $rollover);
        if ($oldFile) {
            $rollover->setOldKeyFile($oldFile);
            $oldDs = $this->fetchDsRecord($sftp, $keyDir, $oldFile);
            $rollover->setOldKeyTag($this->tagFromDs($oldDs) ?? $this->tagFromFile($oldFile));
            $rollover->addLog("Found existing KSK: $oldFile (tag {$rollover->getOldKeyTag()})");
        } else {
            $rollover->addLog('No existing KSK found — generating fresh.');
        }

        $out = $sftp->exec(sprintf(
            'cd %s && sudo -u bind dnssec-keygen -a %s -f KSK %s 2>&1',
            escapeshellarg($keyDir),
            escapeshellarg($algorithm),
            escapeshellarg($zone)
        ));
        $exit = $sftp->getExitStatus();
        $rollover->addLog("dnssec-keygen: " . trim((string)$out));
        if ($exit !== 0) {
            throw new \RuntimeException("dnssec-keygen failed: " . trim((string)$out));
        }

        // dnssec-keygen prints progress dots before the key base name on the last line
        $lines   = array_values(array_filter(array_map('trim', explode("\n", (string)$out))));
        $newFile = end($lines);
        if (!$newFile) {
            throw new \RuntimeException('dnssec-keygen produced no output (expected key base name).');
        }
        $rollover->setNewKeyFile($newFile);

        $ds = $this->fetchDsRecord($sftp, $keyDir, $newFile);
        $rollover->setDsRecord($ds);
        $newTag = $this->tagFromDs($ds) ?? $this->tagFromFile($newFile);
        $rollover->setNewKeyTag($newTag);
        $rollover->addLog("DS record obtained (new key tag: $newTag).");

        $ttl = $this->dnskeyTtlFromPolicy($rollover) ?? $this->probeDnskeyTtl($sftp, $zone);
        if ($ttl !== null) {
            $rollover->setDnskeyTtlSeconds($ttl);
        }

        foreach ($this->zoneViewNames($rollover) as $view) {
            $out = $sftp->exec('sudo rndc loadkeys ' . escapeshellarg($zone) . ' IN ' . escapeshellarg($view) . ' 2>&1');
            $rollover->addLog("rndc loadkeys ($view): " . trim((string)$out));
        }

        $rollover->setStatus(KskRolloverStatus::KeyPublished);
    }

    /**
     * Cleans up the newly generated key after a failed rollover.
     * Marks it inactive+deleted immediately, reloads BIND so it drops the
     * DNSKEY from the zone, then removes the .key and .private files.
     * No-ops if newKeyFile is not set (key was never generated).
     * Does NOT flush — caller is responsible.
     */
    public function cleanupNewKey(DnssecKskRollover $rollover): void
    {
        $newFile = $rollover->getNewKeyFile();
        if (!$newFile) {
            return;
        }

        $sftp    = $this->connect($rollover);
        $zone    = $rollover->getZoneName();
        $keyDir  = rtrim($rollover->getKeyDirectory(), '/');
        $keyPath = $keyDir . '/' . $newFile;

        $settime = $sftp->exec(sprintf(
            'sudo -u bind dnssec-settime -I now -D now %s 2>&1',
            escapeshellarg($keyPath . '.key')
        ));
        $rollover->addLog("Cleanup dnssec-settime: " . trim((string)$settime));

        foreach ($this->zoneViewNames($rollover) as $view) {
            $out = $sftp->exec('sudo rndc loadkeys ' . escapeshellarg($zone) . ' IN ' . escapeshellarg($view) . ' 2>&1');
            $rollover->addLog("Cleanup rndc loadkeys ($view): " . trim((string)$out));
        }

        $rollover->addLog("Cleanup complete: key marked inactive+deleted, BIND will purge the files.");
    }

    /**
     * Runs SSH operations for step 4: dnssec-settime + rndc sign.
     * Updates the rollover entity but does NOT flush — caller is responsible.
     */
    public function retireOldKey(DnssecKskRollover $rollover): void
    {
        $sftp    = $this->connect($rollover);
        $zone    = $rollover->getZoneName();
        $keyDir  = rtrim($rollover->getKeyDirectory(), '/');
        $oldFile = $rollover->getOldKeyFile();

        if (!$oldFile) {
            throw new \RuntimeException('Old key file not set on rollover record.');
        }

        $ttl         = $rollover->getDnskeyTtlSeconds() ?? 3600;
        $deleteDelay = '+' . ($ttl * 2);

        $cmd = sprintf(
            'sudo -u bind dnssec-settime -I now -D %s %s 2>&1',
            escapeshellarg($deleteDelay),
            escapeshellarg($keyDir . '/' . $oldFile . '.key')
        );
        $out  = $sftp->exec($cmd);
        $exit = $sftp->getExitStatus();
        $rollover->addLog("dnssec-settime: " . trim((string)$out));
        if ($exit !== 0) {
            throw new \RuntimeException("dnssec-settime failed: " . trim((string)$out));
        }

        foreach ($this->zoneViewNames($rollover) as $view) {
            $out = $sftp->exec('sudo rndc sign ' . escapeshellarg($zone) . ' IN ' . escapeshellarg($view) . ' 2>&1');
            $rollover->addLog("rndc sign ($view): " . trim((string)$out));
        }

        $rollover->setStatus(KskRolloverStatus::OldKeyRetired);
    }

    // -------------------------------------------------------------------------

    private function connect(DnssecKskRollover $rollover): SFTP
    {
        $server = $rollover->getDnsServer();
        if (!$server) {
            throw new \RuntimeException('No DNS server associated with this rollover.');
        }
        return $this->connectServer($server);
    }

    private function connectServer(DnsServer $server): SFTP
    {
        if (!$server->getSshPrivateKey()) {
            throw new \RuntimeException('No SSH key configured for server "' . $server->getName() . '".');
        }
        return $this->sshKeys->connect($server->getHostname(), $server->getSshUser(), $server->getSshPrivateKey());
    }

    /**
     * Returns DS record lines for all current KSKs for a domain.
     * Returns an empty array when BIND has not yet generated any keys.
     *
     * @return string[]
     */
    public function fetchCurrentDsRecords(Domain $domain, DnsServer $server): array
    {
        return $this->fetchCurrentDsRecordsByZone($domain->getName(), $server);
    }

    /**
     * Returns DS record lines for all current KSKs for a subnet's reverse zone.
     * Returns an empty array when BIND has not yet generated any keys.
     *
     * @return string[]
     */
    public function fetchCurrentDsRecordsForSubnet(Subnet $subnet, DnsServer $server): array
    {
        $zone = $subnet->getReverseZoneName();
        if ($zone === null) {
            throw new \RuntimeException('Subnet has no CIDR — cannot determine reverse zone name.');
        }
        return $this->fetchCurrentDsRecordsByZone($zone, $server);
    }

    /** @return string[] */
    private function fetchCurrentDsRecordsByZone(string $zone, DnsServer $server): array
    {
        $sftp   = $this->connectServer($server);
        $keyDir = rtrim($server->getKeyDirectory() ?? '', '/') . '/' . $zone;

        $out = (string)$sftp->exec(sprintf('dig +short DNSKEY %s 2>/dev/null', escapeshellarg($zone)));

        $dsRecords = [];
        foreach (explode("\n", $out) as $line) {
            $line  = trim($line);
            $parts = preg_split('/\s+/', $line, 4);
            if (!isset($parts[3])) {
                continue;
            }
            [$flagStr, $protoStr, $algStr, $pubKey] = $parts;
            if ((int)$flagStr !== 257) {
                continue;
            }
            $alg     = (int)$algStr;
            $tag     = $this->computeKeyTag((int)$flagStr, (int)$protoStr, $alg, $pubKey);
            $keyFile = sprintf('K%s.+%03d+%05d', $zone, $alg, $tag);
            $ds      = $this->fetchDsRecord($sftp, $keyDir, $keyFile);
            if ($ds !== '') {
                $dsRecords[] = $ds;
            }
        }

        return array_values(array_unique($dsRecords));
    }

    /**
     * Returns metadata for all published DNSKEY records (KSK and ZSK) for a domain.
     * Each entry contains key_tag, type, algorithm, flags, and lifecycle timestamps.
     * Private key material is never read.
     *
     * @return array<int, array{key_tag: int, type: string, algorithm: int, flags: int, created: string|null, publish: string|null, activate: string|null, inactive: string|null, delete: string|null}>
     */
    public function fetchAllKeyInfo(Domain $domain, DnsServer $server): array
    {
        return $this->fetchAllKeyInfoByZone($domain->getName(), $server);
    }

    /**
     * Returns metadata for all published DNSKEY records for a subnet's reverse zone.
     * Private key material is never read.
     *
     * @return array<int, array{key_tag: int, type: string, algorithm: int, flags: int, created: string|null, publish: string|null, activate: string|null, inactive: string|null, delete: string|null}>
     */
    public function fetchAllKeyInfoForSubnet(Subnet $subnet, DnsServer $server): array
    {
        $zone = $subnet->getReverseZoneName();
        if ($zone === null) {
            throw new \RuntimeException('Subnet has no CIDR — cannot determine reverse zone name.');
        }
        return $this->fetchAllKeyInfoByZone($zone, $server);
    }

    /**
     * @return array<int, array{key_tag: int, type: string, algorithm: int, flags: int, created: string|null, publish: string|null, activate: string|null, inactive: string|null, delete: string|null}>
     */
    private function fetchAllKeyInfoByZone(string $zone, DnsServer $server): array
    {
        $sftp   = $this->connectServer($server);
        $keyDir = rtrim($server->getKeyDirectory() ?? '', '/') . '/' . $zone;

        $out = (string)$sftp->exec(sprintf('dig +short DNSKEY %s 2>/dev/null', escapeshellarg($zone)));

        $keys = [];
        foreach (explode("\n", $out) as $line) {
            $line  = trim($line);
            $parts = preg_split('/\s+/', $line, 4);
            if (!isset($parts[3])) {
                continue;
            }
            [$flagStr, $protoStr, $algStr, $pubKey] = $parts;
            $flags = (int)$flagStr;
            if ($flags !== 257 && $flags !== 256) {
                continue;
            }
            $alg      = (int)$algStr;
            $tag      = $this->computeKeyTag($flags, (int)$protoStr, $alg, $pubKey);
            $base     = sprintf('K%s.+%03d+%05d', $zone, $alg, $tag);
            $path     = $keyDir . '/' . $base . '.key';

            // Try reading the .key file directly first — BIND writes timing metadata as
            // comment lines ("; Created: YYYYMMDDHHMMSS …") and the file is typically
            // world-readable. Fall back to dnssec-settime only when cat returns nothing,
            // which happens on deployments where the key directory is bind:bind 750.
            $timing = (string)$sftp->exec('cat ' . escapeshellarg($path) . ' 2>/dev/null');
            if (!str_contains($timing, 'Created:')) {
                $timing = (string)$sftp->exec(
                    'sudo -u bind dnssec-settime -p all ' . escapeshellarg($path) . ' 2>/dev/null'
                );
            }

            $keys[] = [
                'key_tag'   => $tag,
                'type'      => $flags === 257 ? 'KSK' : 'ZSK',
                'algorithm' => $alg,
                'flags'     => $flags,
                'created'   => $this->parseSettime($timing, 'Created'),
                'publish'   => $this->parseSettime($timing, 'Publish'),
                'activate'  => $this->parseSettime($timing, 'Activate'),
                'inactive'  => $this->parseSettime($timing, 'Inactive'),
                'delete'    => $this->parseSettime($timing, 'Delete'),
            ];
        }

        return $keys;
    }

    /**
     * Parses a timing field from either source:
     *  - .key file comments:      "; Created: 20231201120000 (Fri Dec  1 12:00:00 2023)"
     *  - dnssec-settime -p all:   "Created: 20231201120000 (Fri Dec  1 12:00:00 2023)"
     * Returns the 14-digit YYYYMMDDHHMMSS timestamp, or null if the field is unset/absent.
     */
    private function parseSettime(string $output, string $field): ?string
    {
        if (!preg_match('/^;?\s*' . preg_quote($field, '/') . ':\s*(\d{14})/im', $output, $m)) {
            return null;
        }
        return $m[1];
    }

    /**
     * Finds the currently active KSK base name in keyDir for zone.
     *
     * Stage 1: query the live DNSKEY RRset with dig (no sudo, no directory access).
     *   KSK records have flag 257.  The key tag is computed from the DNSKEY RDATA
     *   using the RFC 4034 §B algorithm, which lets us reconstruct the exact file
     *   base name (K{zone}.+{alg:03d}+{tag:05d}) without touching the key directory.
     *   Works with both manually managed zones and dnssec-policy zones.
     *
     * Stage 2: for each KSK candidate, run "sudo -u bind dnssec-settime -p all"
     *   to check whether an Inactive time is already set; if so, the key is
     *   being retired and is skipped.
     *
     * Stage 3: if multiple candidates survive, break the tie against parent DS.
     */
    private function findCurrentKsk(SFTP $sftp, string $keyDir, string $zone, DnssecKskRollover $rollover): ?string
    {
        // dig +short DNSKEY outputs one line per record: "flags proto alg base64key"
        // No sudo needed — reads the live zone via DNS.
        $out = (string)$sftp->exec(sprintf(
            'dig +short DNSKEY %s 2>/dev/null',
            escapeshellarg($zone)
        ));

        $rawCandidates = [];
        foreach (explode("\n", $out) as $line) {
            $line  = trim($line);
            $parts = preg_split('/\s+/', $line, 4);
            if (!isset($parts[3])) {
                continue;
            }
            [$flagStr, $protoStr, $algStr, $pubKey] = $parts;
            if ((int)$flagStr !== 257) {
                continue; // ZSK (256) or unrecognised — not a KSK
            }
            $alg             = (int)$algStr;
            $tag             = $this->computeKeyTag((int)$flagStr, (int)$protoStr, $alg, $pubKey);
            $rawCandidates[] = sprintf('K%s.+%03d+%05d', $zone, $alg, $tag);
        }

        if (empty($rawCandidates)) {
            return null;
        }

        // Stage 2: drop keys that already have an Inactive time set (being retired).
        $candidates = [];
        foreach ($rawCandidates as $base) {
            $path = $keyDir . '/' . $base . '.key';
            $info = (string)$sftp->exec(
                'sudo -u bind dnssec-settime -p all ' . escapeshellarg($path) . ' 2>/dev/null'
            );

            if (preg_match('/^Inactive:\s*(.+)$/im', $info, $m)) {
                $val = strtolower(trim($m[1]));
                if (str_contains($val, 'unset') || str_contains($val, 'not set') || $val === '') {
                    $candidates[] = $base;
                }
                // else: Inactive time is set — key is mid-retirement, skip it
            } else {
                $candidates[] = $base; // no Inactive line → not scheduled for retirement
            }
        }

        if (empty($candidates)) {
            return null;
        }

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        // Stage 3: multiple active KSKs — break tie with parent DS.
        $rollover->addLog(sprintf(
            '%d KSK candidates still active after timing check — querying parent DS to identify trusted key.',
            count($candidates)
        ));

        $parentTags = $this->fetchParentDsTags($sftp, $zone);

        if (!empty($parentTags)) {
            foreach ($candidates as $base) {
                $tag = $this->tagFromFile($base);
                if ($tag !== null && in_array($tag, $parentTags, true)) {
                    $rollover->addLog("Parent DS match: tag $tag → $base");
                    return $base;
                }
            }
            $rollover->addLog('Warning: no candidate key tag matched parent DS records (' . implode(', ', $parentTags) . ').');
        } else {
            $rollover->addLog('Warning: parent DS query returned no records; falling back to first candidate.');
        }

        return $candidates[0];
    }

    /**
     * Computes the DNSSEC key tag from DNSKEY RDATA per RFC 4034 Appendix B.
     */
    private function computeKeyTag(int $flags, int $protocol, int $alg, string $pubKeyB64): int
    {
        $rdata = pack('n', $flags) . chr($protocol) . chr($alg) . base64_decode($pubKeyB64);
        $ac    = 0;
        for ($i = 0, $len = strlen($rdata); $i < $len; $i++) {
            $ac += ($i & 1) ? ord($rdata[$i]) : (ord($rdata[$i]) << 8);
        }
        $ac += ($ac >> 16) & 0xFFFF;
        return $ac & 0xFFFF;
    }

    /**
     * Returns the view names that the domain belongs to on the rollover's DNS server.
     *
     * @return string[]
     */
    private function zoneViewNames(DnssecKskRollover $rollover): array
    {
        $server = $rollover->getDnsServer();
        if (!$server) {
            return [];
        }

        $serverViewIds = [];
        foreach ($server->getViews() as $v) {
            $serverViewIds[$v->getId()] = $v->getName();
        }

        $names = [];
        foreach ($rollover->getEffectiveViews() as $v) {
            if (isset($serverViewIds[$v->getId()])) {
                $names[] = $v->getName();
            }
        }

        return $names;
    }

    /**
     * Returns the set of key tags present in the parent zone's DS RRset for $zone.
     * dig does not access key files and needs no sudo.
     */
    private function fetchParentDsTags(SFTP $sftp, string $zone): array
    {
        $out  = $sftp->exec(sprintf('dig +noall +answer DS %s 2>/dev/null', escapeshellarg($zone)));
        $tags = [];

        foreach (explode("\n", (string)$out) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, ';')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line);
            if (isset($parts[4]) && strtoupper($parts[3] ?? '') === 'DS' && ctype_digit($parts[4])) {
                $tags[] = (int)$parts[4];
            }
        }

        return array_unique($tags);
    }

    /** Parses the key tag from a DS record line. */
    private function tagFromDs(string $dsRecord): ?int
    {
        if (preg_match('/\sDS\s+(\d+)\s/', $dsRecord, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    /** Parses the key tag from the file base name (e.g. Kzone.+013+12345 → 12345). */
    private function tagFromFile(string $fileBaseName): ?int
    {
        if (preg_match('/\+(\d+)$/', $fileBaseName, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    /**
     * Reads dnskey-ttl from the domain's DNSSEC policy.
     * Accepts seconds ("3600") or ISO 8601 durations ("PT1H").
     */
    private function dnskeyTtlFromPolicy(DnssecKskRollover $rollover): ?int
    {
        $policy = $rollover->getEffectiveDnssecPolicy();
        if (!$policy) {
            return null;
        }
        $raw = $policy->getDnskeyTtl();
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_numeric($raw)) {
            return (int)$raw;
        }
        if (preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', strtoupper($raw), $m)) {
            return ((int)($m[1] ?? 0)) * 86400
                 + ((int)($m[2] ?? 0)) * 3600
                 + ((int)($m[3] ?? 0)) * 60
                 + ((int)($m[4] ?? 0));
        }
        return null;
    }

    /**
     * Validates the DNSSEC trust chain from the root zone down to the domain.
     *
     * @return array{steps: list<array<string,mixed>>, valid: bool}
     */
    public function validateTrustChain(Domain $domain, DnsServer $server): array
    {
        return $this->validateTrustChainByZone(rtrim($domain->getName(), '.') . '.', $server);
    }

    /**
     * Validates the DNSSEC trust chain for a subnet's reverse zone.
     *
     * @return array{steps: list<array<string,mixed>>, valid: bool}
     */
    public function validateTrustChainForSubnet(Subnet $subnet, DnsServer $server): array
    {
        $zone = $subnet->getReverseZoneName();
        if ($zone === null) {
            throw new \RuntimeException('Subnet has no CIDR — cannot determine reverse zone name.');
        }
        return $this->validateTrustChainByZone(rtrim($zone, '.') . '.', $server);
    }

    /**
     * Validates the DNSSEC trust chain from the root zone down to $zone.
     *
     * Steps alternate DS → DNSKEY for every zone in the chain (TLD, …, zone), so
     * the caller can visually link each DS key tag to the DNSKEY it anchors.
     * The final zone also gets a 'signing' step that checks SOA RRSIG coverage.
     *
     * No sudo is required — all queries use dig against the configured resolver.
     *
     * @return array{steps: list<array<string,mixed>>, valid: bool}
     */
    private function validateTrustChainByZone(string $zone, DnsServer $server): array
    {
        $sftp   = $this->connectServer($server);
        $labels = $this->buildChainLabels($zone);

        $steps = [];

        foreach ($labels as $i => $child) {
            $parent = $i === 0 ? '.' : $labels[$i - 1];
            $isZone = ($i === count($labels) - 1);

            // DS step: does the parent have a signed DS record for this child?
            $dsOut      = (string) $sftp->exec(sprintf(
                'dig +noall +answer +dnssec DS %s 2>/dev/null',
                escapeshellarg($child)
            ));
            $dsLines    = $this->extractAnswerRecords($dsOut, 'DS');
            $dsSigLines = $this->extractAnswerRecords($dsOut, 'RRSIG');
            $nsecLines  = array_merge(
                $this->extractAnswerRecords($dsOut, 'NSEC'),
                $this->extractAnswerRecords($dsOut, 'NSEC3')
            );

            $dsParsed      = $this->parseDsRecords($dsLines);
            $rrsigSignerTag = $this->parseRrsigKeyTag($dsSigLines);
            $dsKeyTags     = array_column($dsParsed, 'key_tag');

            if (empty($dsLines)) {
                $steps[] = [
                    'type'          => 'ds',
                    'label'         => $parent . ' → ' . $child,
                    'parent'        => $parent,
                    'child'         => $child,
                    'status'        => 'error',
                    'records'       => [],
                    'ds_parsed'     => [],
                    'rrsig_key_tag' => null,
                    'detail'        => !empty($nsecLines)
                        ? 'Parent zone is signed but carries no DS record for this zone — chain is broken'
                        : 'No DS record found in parent zone',
                ];
            } else {
                $steps[] = [
                    'type'          => 'ds',
                    'label'         => $parent . ' → ' . $child,
                    'parent'        => $parent,
                    'child'         => $child,
                    'status'        => empty($dsSigLines) ? 'warning' : 'ok',
                    'records'       => $dsLines,
                    'ds_parsed'     => $dsParsed,
                    'rrsig_key_tag' => $rrsigSignerTag,
                    'detail'        => empty($dsSigLines)
                        ? sprintf('%d DS record(s) found — no RRSIG covering them', count($dsLines))
                        : sprintf('%d DS record(s) — signed by parent zone', count($dsLines)),
                ];
            }

            // DNSKEY step: fetch keys for this zone (intermediate zones included).
            $dnskeyOut    = (string) $sftp->exec(sprintf(
                'dig +noall +answer +dnssec DNSKEY %s 2>/dev/null',
                escapeshellarg($child)
            ));
            $dnskeyLines  = $this->extractAnswerRecords($dnskeyOut, 'DNSKEY');
            $dnskeySigs   = $this->extractAnswerRecords($dnskeyOut, 'RRSIG');
            $dnskeyParsed    = $this->parseDnskeyRecords($dnskeyLines);
            $dnskeySignerTag = $this->parseRrsigKeyTag($dnskeySigs);

            $kskCount = count(array_filter($dnskeyParsed, static fn($k) => $k['type'] === 'KSK'));
            $zskCount = count($dnskeyParsed) - $kskCount;

            if (empty($dnskeyLines)) {
                $steps[] = [
                    'type'                  => 'dnskey',
                    'label'                 => $child . ' DNSKEY',
                    'zone'                  => $child,
                    'status'                => 'error',
                    'records'               => [],
                    'dnskeys_parsed'        => [],
                    'anchored_by'           => $dsKeyTags,
                    'dnskey_signer_key_tag' => null,
                    'detail'                => 'No DNSKEY records found',
                ];
            } else {
                $steps[] = [
                    'type'                  => 'dnskey',
                    'label'                 => $child . ' DNSKEY',
                    'zone'                  => $child,
                    'status'                => empty($dnskeySigs) ? 'warning' : 'ok',
                    'records'               => $dnskeyLines,
                    'dnskeys_parsed'        => $dnskeyParsed,
                    'anchored_by'           => $dsKeyTags,
                    'dnskey_signer_key_tag' => $dnskeySignerTag,
                    'detail'                => sprintf(
                        '%d KSK, %d ZSK%s',
                        $kskCount,
                        $zskCount,
                        empty($dnskeySigs) ? ' — no RRSIG' : ' — signed by zone\'s own KSK (normal)'
                    ),
                ];
            }

            // Signing step: only for the managed zone itself.
            if ($isZone) {
                $soaOut        = (string) $sftp->exec(sprintf(
                    'dig +noall +answer +dnssec SOA %s 2>/dev/null',
                    escapeshellarg($zone)
                ));
                $soaLines      = $this->extractAnswerRecords($soaOut, 'SOA');
                $soaSigs       = $this->extractAnswerRecords($soaOut, 'RRSIG');
                $soaSignerTag  = $this->parseRrsigKeyTag($soaSigs);

                $steps[] = [
                    'type'               => 'signing',
                    'label'              => $zone . ' zone signing',
                    'zone'               => $zone,
                    'status'             => empty($soaSigs) ? 'error' : 'ok',
                    'records'            => $soaLines,
                    'soa_signer_key_tag' => $soaSignerTag,
                    'detail'             => empty($soaSigs)
                        ? 'SOA record is not signed'
                        : 'Zone records are signed (SOA has RRSIG)',
                ];
            }
        }

        $valid = !in_array('error', array_column($steps, 'status'), true);

        return ['steps' => $steps, 'valid' => $valid];
    }

    /**
     * Builds the chain of DNS labels from TLD to zone.
     * Example: 'example.com.' → ['com.', 'example.com.']
     *
     * @return list<string>
     */
    private function buildChainLabels(string $zone): array
    {
        $parts  = explode('.', rtrim($zone, '.'));
        $result = [];
        for ($i = 1; $i <= count($parts); $i++) {
            $result[] = implode('.', array_slice($parts, -$i)) . '.';
        }
        return $result;
    }

    /**
     * Extracts answer-section lines of the given RR type from dig +noall +answer output.
     *
     * @return list<string>
     */
    private function extractAnswerRecords(string $digOutput, string $type): array
    {
        $lines = [];
        foreach (explode("\n", $digOutput) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, ';')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line);
            if (isset($parts[3]) && strtoupper($parts[3]) === strtoupper($type)) {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    /**
     * Parses DS record lines into structured arrays.
     * Format: name ttl IN DS key_tag algorithm digest_type digest
     *
     * @param  list<string> $lines
     * @return list<array{key_tag: int, algorithm: int, digest_type: int}>
     */
    private function parseDsRecords(array $lines): array
    {
        $parsed = [];
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 7) {
                continue;
            }
            $parsed[] = [
                'key_tag'     => (int) $parts[4],
                'algorithm'   => (int) $parts[5],
                'digest_type' => (int) $parts[6],
            ];
        }
        return $parsed;
    }

    /**
     * Parses DNSKEY record lines and computes the key tag for each.
     * Format: name ttl IN DNSKEY flags protocol algorithm pubkey...
     *
     * @param  list<string> $lines
     * @return list<array{key_tag: int, type: string, algorithm: int, flags: int}>
     */
    private function parseDnskeyRecords(array $lines): array
    {
        $parsed = [];
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 8) {
                continue;
            }
            $flags  = (int) $parts[4];
            $proto  = (int) $parts[5];
            $alg    = (int) $parts[6];
            // dig may wrap long base64 keys with a space; strip all whitespace before decoding
            $pubKey = preg_replace('/\s+/', '', implode('', array_slice($parts, 7)));
            $tag    = $this->computeKeyTag($flags, $proto, $alg, $pubKey);
            $parsed[] = [
                'key_tag'   => $tag,
                'type'      => $flags === 257 ? 'KSK' : 'ZSK',
                'algorithm' => $alg,
                'flags'     => $flags,
            ];
        }
        return $parsed;
    }

    /**
     * Returns the signing key tag from the first RRSIG line, or null.
     * RRSIG format: name ttl IN RRSIG type alg labels orig_ttl expiry inception key_tag signer sig
     *
     * @param list<string> $rrsigLines
     */
    private function parseRrsigKeyTag(array $rrsigLines): ?int
    {
        foreach ($rrsigLines as $line) {
            $parts = preg_split('/\s+/', $line);
            if (isset($parts[10]) && ctype_digit($parts[10])) {
                return (int) $parts[10];
            }
        }
        return null;
    }

    /** Probes the DNSKEY TTL via dig — no sudo required. */
    private function probeDnskeyTtl(SFTP $sftp, string $zone): ?int
    {
        $out = $sftp->exec(sprintf(
            "dig +noall +answer DNSKEY %s 2>/dev/null | awk '{print $2; exit}'",
            escapeshellarg($zone)
        ));
        $ttl = trim((string)$out);
        return is_numeric($ttl) ? (int)$ttl : null;
    }

    private function fetchDsRecord(SFTP $sftp, string $keyDir, string $keyFile): string
    {
        $out = $sftp->exec(sprintf(
            'sudo -u bind dnssec-dsfromkey %s 2>&1',
            escapeshellarg($keyDir . '/' . $keyFile . '.key')
        ));
        return trim((string)$out);
    }
}
