<?php

namespace App\Service;

class BindZoneFileParser
{
    private const SUPPORTED_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT', 'PTR', 'SRV', 'DS', 'CAA', 'HTTPS'];

    private const ALL_RECORD_TYPES = [
        'A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT', 'PTR', 'SRV', 'SOA',
        'CAA', 'DNSKEY', 'DS', 'HTTPS', 'RRSIG', 'NSEC', 'NSEC3', 'NAPTR',
        'SSHFP', 'TLSA', 'HINFO', 'RP', 'AFSDB', 'LOC', 'SPF',
    ];

    private const CLASS_TOKENS = ['IN', 'CH', 'HS', 'ANY'];

    public function parse(string $content, string $assumedOrigin = ''): array
    {
        $result = [
            'records' => [],
            'errors'  => [],
            'origin'  => $assumedOrigin,
        ];

        $flattened = $this->flattenContent($content);
        $lines     = explode("\n", $flattened);

        $origin         = $assumedOrigin !== '' ? rtrim($assumedOrigin, '.') . '.' : '';
        $defaultTtl     = null;
        $lastName       = null;
        $pendingComment = null;
        $inCommentBlock = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                $pendingComment = null;
                $inCommentBlock = false;
                continue;
            }

            if (str_starts_with($trimmed, ';')) {
                $commentText = trim(substr($trimmed, 1));
                if ($inCommentBlock) {
                    $pendingComment .= "\n" . $commentText;
                } else {
                    $pendingComment = $commentText;
                    $inCommentBlock = true;
                }
                continue;
            }

            $inCommentBlock = false;

            if ($trimmed[0] === '$') {
                $parts     = preg_split('/\s+/', $trimmed, 3);
                $directive = strtoupper($parts[0]);
                if ($directive === '$ORIGIN') {
                    $origin          = $parts[1] ?? $origin;
                    $result['origin'] = rtrim($origin, '.');
                } elseif ($directive === '$TTL') {
                    $defaultTtl = $this->parseTtlValue($parts[1] ?? '3600');
                }
                continue;
            }

            $record = $this->parseLine($line, $lastName, $defaultTtl, $origin);
            if ($record === null) {
                continue;
            }

            $lastName = $record['raw_name'];

            if (!in_array($record['type'], self::SUPPORTED_TYPES, true)) {
                continue;
            }

            $record['comment']    = $pendingComment;
            $result['records'][] = $record;
        }

        return $result;
    }

    private function flattenContent(string $content): string
    {
        $result    = '';
        $depth     = 0;
        $i         = 0;
        $len       = strlen($content);
        $lineEmpty = true;

        while ($i < $len) {
            $ch = $content[$i];

            if ($ch === ';') {
                if ($lineEmpty && $depth === 0) {
                    // Full-line comment — preserve so parse() can attach it to records.
                    $start = $i;
                    while ($i < $len && $content[$i] !== "\n") {
                        $i++;
                    }
                    $result .= substr($content, $start, $i - $start);
                    // Leave the \n to be handled by the \n branch below.
                } else {
                    // Inline comment — strip to end of line.
                    while ($i < $len && $content[$i] !== "\n") {
                        $i++;
                    }
                }
                continue;
            }

            if ($ch === '"') {
                $lineEmpty = false;
                $result   .= $ch;
                $i++;
                while ($i < $len && $content[$i] !== '"') {
                    if ($content[$i] === '\\' && $i + 1 < $len) {
                        $result .= $content[$i++];
                    }
                    $result .= $content[$i++];
                }
                if ($i < $len) {
                    $result .= $content[$i++];
                }
                continue;
            }

            if ($ch === '(') {
                $lineEmpty = false;
                $depth++;
                $i++;
                continue;
            }

            if ($ch === ')') {
                if ($depth > 0) {
                    $depth--;
                }
                $i++;
                continue;
            }

            if ($ch === "\n") {
                $result   .= $depth > 0 ? ' ' : "\n";
                $lineEmpty = true;
                $i++;
                continue;
            }

            if ($ch !== ' ' && $ch !== "\t") {
                $lineEmpty = false;
            }
            $result .= $ch;
            $i++;
        }

        return $result;
    }

    private function parseLine(string $line, ?string $lastName, ?int $defaultTtl, string $origin): ?array
    {
        $inheritName = strlen($line) > 0 && ($line[0] === ' ' || $line[0] === "\t");
        $line        = trim($line);
        if ($line === '') {
            return null;
        }

        $tokens = preg_split('/\s+/', $line);
        $tokens = array_values(array_filter($tokens, fn($t) => $t !== ''));
        if (count($tokens) < 2) {
            return null;
        }

        $pos     = 0;
        $rawName = null;

        if (!$inheritName) {
            $first      = $tokens[0];
            $firstUpper = strtoupper($first);

            if (
                in_array($firstUpper, self::ALL_RECORD_TYPES, true) ||
                in_array($firstUpper, self::CLASS_TOKENS, true) ||
                ctype_digit($first)
            ) {
                $rawName = $lastName;
            } else {
                $rawName = $first;
                $pos++;
            }
        } else {
            $rawName = $lastName;
        }

        if ($rawName === null) {
            return null;
        }

        // Optional TTL
        $ttl = $defaultTtl;
        if (isset($tokens[$pos]) && ctype_digit($tokens[$pos])) {
            $ttl = (int) $tokens[$pos];
            $pos++;
        }

        // Optional class
        if (isset($tokens[$pos]) && in_array(strtoupper($tokens[$pos]), self::CLASS_TOKENS, true)) {
            $pos++;
        }

        // Type
        if (!isset($tokens[$pos])) {
            return null;
        }
        $type = strtoupper($tokens[$pos]);
        $pos++;

        // Value
        if (!isset($tokens[$pos])) {
            return null;
        }
        $value = implode(' ', array_slice($tokens, $pos));
        $value = $this->normalizeValue($type, $value);

        $label = $this->normalizeLabel($rawName, $origin);

        return [
            'name'     => $label,
            'raw_name' => $rawName,
            'type'     => $type,
            'value'    => $value,
            'ttl'      => $ttl,
        ];
    }

    private function normalizeLabel(string $name, string $origin): string
    {
        if ($name === '@') {
            return '@';
        }

        if (str_ends_with($name, '.')) {
            $fqdn   = rtrim($name, '.');
            $domain = rtrim($origin, '.');
            if ($domain !== '' && strcasecmp(substr($fqdn, -strlen($domain)), $domain) === 0) {
                $label = substr($fqdn, 0, strlen($fqdn) - strlen($domain));
                $label = rtrim($label, '.');
                return $label === '' ? '@' : $label;
            }
            return $fqdn;
        }

        return $name;
    }

    private function normalizeValue(string $type, string $value): string
    {
        if ($type === 'TXT') {
            $parts = [];
            preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $value, $matches);
            if (!empty($matches[1])) {
                return implode('', array_map(
                    fn($p) => str_replace('\\"', '"', $p),
                    $matches[1]
                ));
            }
            return trim($value, '"');
        }

        if (in_array($type, ['CNAME', 'NS', 'PTR', 'MX', 'SRV'], true)) {
            return rtrim($value, '.');
        }

        return $value;
    }

    private function parseTtlValue(string $value): int
    {
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $units = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800];
        $total = 0;
        preg_match_all('/(\d+)([smhdwSMHDW]?)/', $value, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $unit   = strtolower($m[2] ?: 's');
            $total += (int) $m[1] * ($units[$unit] ?? 1);
        }
        return $total ?: 3600;
    }
}
