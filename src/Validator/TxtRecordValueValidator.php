<?php

namespace App\Validator;

class TxtRecordValueValidator
{
    /**
     * Validate structured TXT record content based on hostname and value conventions.
     * Returns an array of human-readable error strings; empty means valid.
     */
    public static function validate(string $hostname, string $value): array
    {
        if (stripos($value, 'v=spf1') === 0) {
            return self::validateSpf($value);
        }
        if (str_ends_with(strtolower($hostname), '._domainkey')) {
            return self::validateDkim($value);
        }
        if (self::isDmarcHostname($hostname)) {
            return self::validateDmarc($value);
        }
        return [];
    }

    private static function isDmarcHostname(string $hostname): bool
    {
        $lower = strtolower($hostname);
        return $lower === '_dmarc' || str_starts_with($lower, '_dmarc.');
    }

    private static function validateSpf(string $value): array
    {
        $tokens = preg_split('/\s+/', trim($value));
        array_shift($tokens); // remove v=spf1

        $errors = [];
        $hasTerminator = false;

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (preg_match('/^[+\-~?]?all$/i', $token) || preg_match('/^redirect=/i', $token)) {
                $hasTerminator = true;
            }
            if (!self::isValidSpfToken($token)) {
                $errors[] = sprintf('SPF record contains an invalid mechanism or modifier: "%s".', $token);
            }
        }

        if (!$hasTerminator) {
            $errors[] = 'SPF record must end with an "all" mechanism or "redirect=" modifier.';
        }

        return $errors;
    }

    private static function isValidSpfToken(string $token): bool
    {
        return (bool) preg_match(
            '/^[+\-~?]?(all|include:.+|a(:[^\/\s]+)?(\/\d+)?(\/\d+)?|mx(:[^\/\s]+)?(\/\d+)?(\/\d+)?|ptr(:.+)?|ip4:\d{1,3}(\.\d{1,3}){3}(\/\d+)?|ip6:[0-9a-fA-F:.]+(%[a-zA-Z0-9._-]+)?(\/\d+)?|exists:.+|redirect=.+|exp=.+)$/i',
            $token
        );
    }

    private static function validateDkim(string $value): array
    {
        $tags = self::parseSemicolonTags($value);
        $errors = [];

        if (!isset($tags['v']) || strtoupper($tags['v']) !== 'DKIM1') {
            $errors[] = 'DKIM record must contain "v=DKIM1".';
        }

        if (!array_key_exists('p', $tags)) {
            $errors[] = 'DKIM record must contain a "p=" tag (public key, or empty for revocation).';
        }

        if (isset($tags['k']) && !in_array(strtolower($tags['k']), ['rsa', 'ed25519'], true)) {
            $errors[] = sprintf('DKIM "k=" tag must be "rsa" or "ed25519", got "%s".', $tags['k']);
        }

        return $errors;
    }

    private static function validateDmarc(string $value): array
    {
        $parts = explode(';', $value);
        $errors = [];

        $firstPart = trim($parts[0] ?? '');
        if (strtolower($firstPart) !== 'v=dmarc1') {
            $errors[] = 'DMARC record must begin with "v=DMARC1".';
        }

        $tags = self::parseSemicolonTags($value);

        if (!isset($tags['p'])) {
            $errors[] = 'DMARC record must contain a "p=" policy tag.';
        } elseif (!in_array(strtolower($tags['p']), ['none', 'quarantine', 'reject'], true)) {
            $errors[] = sprintf('DMARC "p=" must be "none", "quarantine", or "reject", got "%s".', $tags['p']);
        }

        if (isset($tags['sp']) && !in_array(strtolower($tags['sp']), ['none', 'quarantine', 'reject'], true)) {
            $errors[] = sprintf('DMARC "sp=" must be "none", "quarantine", or "reject", got "%s".', $tags['sp']);
        }

        foreach (['adkim', 'aspf'] as $tag) {
            if (isset($tags[$tag]) && !in_array(strtolower($tags[$tag]), ['r', 's'], true)) {
                $errors[] = sprintf('DMARC "%s=" must be "r" (relaxed) or "s" (strict), got "%s".', $tag, $tags[$tag]);
            }
        }

        if (isset($tags['pct'])) {
            $pct = filter_var($tags['pct'], FILTER_VALIDATE_INT);
            if ($pct === false || $pct < 0 || $pct > 100) {
                $errors[] = sprintf('DMARC "pct=" must be an integer between 0 and 100, got "%s".', $tags['pct']);
            }
        }

        return $errors;
    }

    private static function parseSemicolonTags(string $value): array
    {
        $tags = [];
        foreach (explode(';', $value) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $eqPos = strpos($part, '=');
            if ($eqPos === false) {
                continue;
            }
            $key = strtolower(trim(substr($part, 0, $eqPos)));
            $val = trim(substr($part, $eqPos + 1));
            $tags[$key] = $val;
        }
        return $tags;
    }
}
