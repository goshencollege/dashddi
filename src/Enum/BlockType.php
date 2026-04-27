<?php

namespace App\Enum;

enum BlockType: string
{
    case Reserved = 'reserved';
    case Dynamic  = 'dynamic';
    case Fixed    = 'fixed';

    public function label(): string
    {
        return match($this) {
            self::Reserved => 'Reserved',
            self::Dynamic  => 'Dynamic',
            self::Fixed    => 'Fixed',
        };
    }

    public function badgeClass(): string
    {
        return match($this) {
            self::Reserved => 'danger',
            self::Dynamic  => 'warning',
            self::Fixed    => 'success',
        };
    }
}
