<?php

namespace App\Service;

/**
 * Parses the structured host search syntax (`field:value AND/OR field:value`, with
 * `!` negation, `"quoted values"`, and parenthesized grouping) shared by the host
 * web UI and the hosts API.
 */
class HostQueryParser
{
    private const KNOWN_FIELDS = ['name', 'building', 'room', 'subnet', 'ip', 'mac', 'duid', 'dns', 'tag',
        'dhcp_mismatch', 'last_dhcp', 'last_auth', 'switch_ip', 'switch_port', 'deleted'];

    /**
     * Parse a structured query string into OR-groups of AND-conditions.
     * Each condition: [field, value, negate].
     * Returns [] for plain-text queries with no known field:value tokens.
     *
     * @return array<array<array{string, string, bool}>>
     */
    public function parse(string $q): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }

        $fieldPattern = implode('|', self::KNOWN_FIELDS);
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
}
