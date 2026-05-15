<?php

namespace App\Enum;

enum SchedulableTask: string
{
    case PushClearpass  = 'push_clearpass';
    case PushDns        = 'push_dns';
    case PushDhcp       = 'push_dhcp';
    case PushRadius     = 'push_radius';
    case PurgeLeases    = 'purge_leases';
    case PurgePushLogs  = 'purge_push_logs';
    case DatabaseBackup = 'database_backup';

    public function label(): string
    {
        return match($this) {
            self::PushClearpass  => 'Push ClearPass Endpoints',
            self::PushDns        => 'Push DNS Configs',
            self::PushDhcp       => 'Push DHCP Configs',
            self::PushRadius     => 'Push RADIUS Configs',
            self::PurgeLeases    => 'Purge DHCP Lease Logs',
            self::PurgePushLogs  => 'Purge Push Logs',
            self::DatabaseBackup => 'Database Backup',
        };
    }

    public function description(): string
    {
        return match($this) {
            self::PushClearpass  => 'Syncs interface data to the endpoint repository on all configured ClearPass servers. Creates, updates, and removes only endpoints managed by DashDDI.',
            self::PushDns        => 'Generates BIND zone files and views.conf, then deploys them to all configured DNS servers.',
            self::PushDhcp       => 'Generates DHCP subnet configuration and deploys it to all configured DHCP servers.',
            self::PushRadius     => 'Generates FreeRADIUS clients.conf from RADIUS client data and deploys it to all configured RADIUS servers.',
            self::PurgeLeases    => 'Deletes DHCP lease log entries that exceed each subnet\'s configured retention period. A default retention period (for subnets with no per-subnet setting and unmatched leases) can be configured in Application Settings.',
            self::PurgePushLogs  => 'Deletes DNS/DHCP push log entries older than the retention period configured in Application Settings (default 30 days).',
            self::DatabaseBackup => 'Creates a database backup using the destination and options configured in Backup Settings.',
        };
    }

    /** Console command and arguments to execute for this task. */
    public function consoleCommand(): string
    {
        return match($this) {
            self::PushClearpass  => 'app:push-clearpass',
            self::PushDns        => 'app:generate-dns-config --deploy',
            self::PushDhcp       => 'app:generate-dhcp-config --reload',
            self::PushRadius     => 'app:generate-radius-config',
            self::PurgeLeases    => 'app:purge-dhcp-leases',
            self::PurgePushLogs  => 'app:purge-push-logs',
            self::DatabaseBackup => 'app:database:backup',
        };
    }

    public function defaultCron(): string
    {
        return match($this) {
            self::PushClearpass  => '0 2 * * *',
            self::PushDns        => '0 2 * * *',
            self::PushDhcp       => '0 2 * * *',
            self::PushRadius     => '0 2 * * *',
            self::PurgeLeases    => '0 3 * * 0',
            self::PurgePushLogs  => '0 3 * * 0',
            self::DatabaseBackup => '0 2 * * *',
        };
    }
}
