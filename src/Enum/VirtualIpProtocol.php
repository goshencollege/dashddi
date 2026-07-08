<?php

namespace App\Enum;

enum VirtualIpProtocol: string
{
    case Vrrp          = 'vrrp';
    case Hsrp          = 'hsrp';
    case ActiveGateway = 'active_gateway';
    case Other         = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Vrrp          => 'VRRP',
            self::Hsrp          => 'HSRP',
            self::ActiveGateway => 'Active-Gateway',
            self::Other         => 'Other',
        };
    }
}
