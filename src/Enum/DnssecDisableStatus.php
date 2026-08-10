<?php

namespace App\Enum;

enum DnssecDisableStatus: string
{
    case AwaitingDsRemoval = 'awaiting_ds_removal'; // waiting for registrar DS removal
    case DsRemoved         = 'ds_removed';          // DS removed, waiting for propagation
    case KeysRetired       = 'keys_retired';        // keys retired on server, zone unsigning
    case Complete          = 'complete';
    case Failed            = 'failed';

    public function label(): string
    {
        return match($this) {
            self::AwaitingDsRemoval => 'Awaiting DS Removal',
            self::DsRemoved         => 'DS Removed — Propagating',
            self::KeysRetired       => 'Keys Retired',
            self::Complete          => 'Complete',
            self::Failed            => 'Failed',
        };
    }

    public function badgeClass(): string
    {
        return match($this) {
            self::AwaitingDsRemoval => 'bg-warning text-dark',
            self::DsRemoved         => 'bg-warning text-dark',
            self::KeysRetired       => 'bg-info text-dark',
            self::Complete          => 'bg-success',
            self::Failed            => 'bg-danger',
        };
    }

    public function isActive(): bool
    {
        return !in_array($this, [self::Complete, self::Failed]);
    }
}
