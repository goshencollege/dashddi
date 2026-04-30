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
