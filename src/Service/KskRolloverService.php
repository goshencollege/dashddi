<?php

namespace App\Service;

use App\Entity\DnssecKskRollover;
use App\Enum\KskRolloverStatus;
use phpseclib3\Net\SFTP;

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

        // Find existing KSK before generating the new one
        $oldFile = $this->findCurrentKsk($sftp, $keyDir, $zone);
        if ($oldFile) {
            $rollover->setOldKeyFile($oldFile);
            $oldDs = $this->fetchDsRecord($sftp, $keyDir, $oldFile);
            $rollover->setOldKeyTag($this->tagFromDs($oldDs) ?? $this->tagFromFile($oldFile));
            $rollover->addLog("Found existing KSK: $oldFile (tag {$rollover->getOldKeyTag()})");
        } else {
            $rollover->addLog('No existing KSK found — generating fresh.');
        }

        // Generate new KSK
        $out = $sftp->exec(sprintf(
            'cd %s && dnssec-keygen -a %s -f KSK %s 2>&1',
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

        // Get DS record — use it as the authoritative source of the key tag
        $ds = $this->fetchDsRecord($sftp, $keyDir, $newFile);
        $rollover->setDsRecord($ds);
        $newTag = $this->tagFromDs($ds) ?? $this->tagFromFile($newFile);
        $rollover->setNewKeyTag($newTag);
        $rollover->addLog("DS record obtained (new key tag: $newTag).");

        // DNSKEY TTL: try policy first, fall back to dig
        $ttl = $this->dnskeyTtlFromPolicy($rollover) ?? $this->probeDnskeyTtl($sftp, $zone);
        if ($ttl !== null) {
            $rollover->setDnskeyTtlSeconds($ttl);
        }

        $rndcOut = $sftp->exec('rndc loadkeys ' . escapeshellarg($zone) . ' 2>&1');
        $rollover->addLog("rndc loadkeys: " . trim((string)$rndcOut));

        $rollover->setStatus(KskRolloverStatus::KeyPublished);
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
            'dnssec-settime -I now -D %s %s 2>&1',
            escapeshellarg($deleteDelay),
            escapeshellarg($keyDir . '/' . $oldFile . '.key')
        );
        $out  = $sftp->exec($cmd);
        $exit = $sftp->getExitStatus();
        $rollover->addLog("dnssec-settime: " . trim((string)$out));
        if ($exit !== 0) {
            throw new \RuntimeException("dnssec-settime failed: " . trim((string)$out));
        }

        $signOut = $sftp->exec('rndc sign ' . escapeshellarg($zone) . ' 2>&1');
        $rollover->addLog("rndc sign: " . trim((string)$signOut));

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
     * Finds the current KSK base name in keyDir for zone.
     * Uses ls + xargs to avoid glob-expansion failures when no files match.
     */
    private function findCurrentKsk(SFTP $sftp, string $keyDir, string $zone): ?string
    {
        $out = $sftp->exec(sprintf(
            'ls %s/K%s.+*.key 2>/dev/null | xargs grep -l " 257 " 2>/dev/null | head -1',
            $keyDir,
            $zone
        ));
        $path = trim((string)$out);
        if (!$path) {
            return null;
        }
        return basename($path, '.key') ?: null;
    }

    /**
     * Parses the key tag from a DS record line.
     * Format: "zone. TTL IN DS <tag> <alg> <digest-type> <digest>"
     */
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
     * The value may be in seconds ("3600") or ISO 8601 ("PT1H").
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
        // Parse simple ISO 8601 durations: PT3600S, PT1H, P1D, PT30M, etc.
        if (preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', strtoupper($raw), $m)) {
            return ((int)($m[1] ?? 0)) * 86400
                 + ((int)($m[2] ?? 0)) * 3600
                 + ((int)($m[3] ?? 0)) * 60
                 + ((int)($m[4] ?? 0));
        }
        return null;
    }

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
            'dnssec-dsfromkey %s 2>&1',
            escapeshellarg($keyDir . '/' . $keyFile . '.key')
        ));
        return trim((string)$out);
    }
}
