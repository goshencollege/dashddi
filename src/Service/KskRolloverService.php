<?php

namespace App\Service;

use App\Entity\DnssecKskRollover;
use App\Enum\KskRolloverStatus;
use phpseclib3\Net\SFTP;

/**
 * All commands that touch bind-owned key files run under "sudo -u bind".
 * All rndc commands run under "sudo" (as root).
 *
 * Required sudoers entries on each DNS server:
 *   ipam ALL=(bind) NOPASSWD: /usr/sbin/dnssec-keygen, /usr/sbin/dnssec-settime, /usr/sbin/dnssec-dsfromkey
 *   ipam ALL=(root) NOPASSWD: /usr/sbin/rndc
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
        $zone      = $rollover->getDomain()->getName();
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
        $zone    = $rollover->getDomain()->getName();
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
        $zone    = $rollover->getDomain()->getName();
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
        if (!$server->getSshPrivateKey()) {
            throw new \RuntimeException('No SSH key configured for server "' . $server->getName() . '".');
        }
        return $this->sshKeys->connect($server->getHostname(), $server->getSshUser(), $server->getSshPrivateKey());
    }

    /**
     * Finds the currently active KSK base name in keyDir for zone.
     *
     * Lists .key files via shell glob (no sudo needed — only requires directory
     * execute permission), then reads each with "sudo -u bind dnssec-settime -p all"
     * to check both the KSK flag (257) and Inactive timing in one command.
     *
     * Stage 1: filter to files that are KSKs with no Inactive time set.
     * Stage 2: if multiple candidates remain, break the tie against parent DS.
     */
    private function findCurrentKsk(SFTP $sftp, string $keyDir, string $zone, DnssecKskRollover $rollover): ?string
    {
        // Shell glob listing — runs as ipam, needs only directory x bit
        $listOut  = $sftp->exec(sprintf(
            'for f in %s/K%s.+*.key; do [ -f "$f" ] && echo "$f"; done 2>/dev/null',
            $keyDir,
            $zone
        ));
        $allFiles = array_values(array_filter(array_map('trim', explode("\n", (string)$listOut))));
        if (empty($allFiles)) {
            return null;
        }

        $candidates = [];
        foreach ($allFiles as $path) {
            // sudo -u bind dnssec-settime -p all reads the file and outputs flags + timing
            $info = $sftp->exec('sudo -u bind dnssec-settime -p all ' . escapeshellarg($path) . ' 2>/dev/null');
            $info = (string)$info;

            // Must be a KSK (Flags: 257)
            if (!preg_match('/^Flags:\s*257\b/im', $info)) {
                continue;
            }

            // Must not have an Inactive time set
            if (preg_match('/^Inactive:\s*(.+)$/im', $info, $m)) {
                $val = strtolower(trim($m[1]));
                if (str_contains($val, 'unset') || str_contains($val, 'not set') || $val === '') {
                    $candidates[] = $path;
                }
            } else {
                $candidates[] = $path;
            }
        }

        if (empty($candidates)) {
            return null;
        }

        if (count($candidates) === 1) {
            return basename($candidates[0], '.key');
        }

        // ── Stage 2: break tie using parent-zone DS records ──────────────────
        $rollover->addLog(sprintf(
            '%d KSK candidates still active after timing check — querying parent DS to identify trusted key.',
            count($candidates)
        ));

        $parentTags = $this->fetchParentDsTags($sftp, $zone);

        if (!empty($parentTags)) {
            foreach ($candidates as $path) {
                $base = basename($path, '.key');
                $tag  = $this->tagFromFile($base);
                if ($tag !== null && in_array($tag, $parentTags, true)) {
                    $rollover->addLog("Parent DS match: tag $tag → $base");
                    return $base;
                }
            }
            $rollover->addLog('Warning: no candidate key tag matched parent DS records (' . implode(', ', $parentTags) . ').');
        } else {
            $rollover->addLog('Warning: parent DS query returned no records; falling back to first candidate.');
        }

        return basename($candidates[0], '.key');
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
        foreach ($rollover->getDomain()->getViews() as $v) {
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
        $policy = $rollover->getDomain()->getDnssecPolicy();
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
