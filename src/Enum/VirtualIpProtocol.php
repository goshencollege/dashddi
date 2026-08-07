<?php

namespace App\Enum;

enum VirtualIpProtocol: string
{
    case Vrrp          = 'vrrp';
    case Hsrp          = 'hsrp';
    case ActiveGateway = 'active_gateway';
    case Anycast       = 'anycast';
    case Other         = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Vrrp          => 'VRRP',
            self::Hsrp          => 'HSRP',
            self::ActiveGateway => 'Active-Gateway',
            self::Anycast       => 'Anycast',
            self::Other         => 'Other',
        };
    }
}
