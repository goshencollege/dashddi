<?php

namespace App\Enum;

enum TsigAlgorithm: string
{
    case HmacMd5    = 'hmac-md5';
    case HmacSha1   = 'hmac-sha1';
    case HmacSha224 = 'hmac-sha224';
    case HmacSha256 = 'hmac-sha256';
    case HmacSha384 = 'hmac-sha384';
    case HmacSha512 = 'hmac-sha512';

    public function label(): string
    {
        return strtoupper($this->value);
    }

    /** Algorithm name as expected by BIND (lowercase). */
    public function bindName(): string
    {
        return $this->value;
    }

    /** Algorithm name as expected by Kea DHCP-DDNS (uppercase). */
    public function keaName(): string
    {
        return strtoupper($this->value);
    }
}
