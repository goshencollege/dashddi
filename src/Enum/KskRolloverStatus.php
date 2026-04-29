<?php

namespace App\Enum;

enum KskRolloverStatus: string
{
    case KeyPublished  = 'key_published';  // new KSK generated + rndc loadkeys run
    case DsPending     = 'ds_pending';     // DNSKEY propagated, registrar DS update needed
    case DsSubmitted   = 'ds_submitted';   // DS submitted, waiting for propagation
    case OldKeyRetired = 'old_retired';    // retire commands run, waiting for cleanup
    case Complete      = 'complete';
    case Failed        = 'failed';

    public function label(): string
    {
        return match($this) {
            self::KeyPublished  => 'Key Published',
            self::DsPending     => 'DS Update Needed',
            self::DsSubmitted   => 'DS Propagating',
            self::OldKeyRetired => 'Old Key Retiring',
            self::Complete      => 'Complete',
            self::Failed        => 'Failed',
        };
    }

    public function badgeClass(): string
    {
        return match($this) {
            self::KeyPublished  => 'bg-info text-dark',
            self::DsPending     => 'bg-warning text-dark',
            self::DsSubmitted   => 'bg-warning text-dark',
            self::OldKeyRetired => 'bg-info text-dark',
            self::Complete      => 'bg-success',
            self::Failed        => 'bg-danger',
        };
    }

    public function isActive(): bool
    {
        return !in_array($this, [self::Complete, self::Failed]);
    }
}
