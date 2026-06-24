<?php

namespace App\Enum;

enum RecordType: string
{
    case A     = 'A';
    case AAAA  = 'AAAA';
    case CNAME = 'CNAME';
    case MX    = 'MX';
    case NS    = 'NS';
    case TXT   = 'TXT';
    case PTR   = 'PTR';
    case SRV   = 'SRV';
    case DS    = 'DS';
    case CAA   = 'CAA';
    case HTTPS = 'HTTPS';
}
