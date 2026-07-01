<?php

namespace App\Enum;

enum SchedulableTask: string
{
    case PushClearpass           = 'push_clearpass';
    case PullClearpassLogs       = 'pull_clearpass_logs';
    case PurgeClearpassAuthLogs  = 'purge_clearpass_auth_logs';
    case PushDns                 = 'push_dns';
    case PushDhcp                = 'push_dhcp';
    case PurgeLeases             = 'purge_leases';
    case PurgePushLogs           = 'purge_push_logs';
    case PurgeDeletedHosts       = 'purge_deleted_hosts';
    case DatabaseBackup          = 'database_backup';
    case PullSnipeIt             = 'pull_snipe_it';
    case PurgeActivityLogs       = 'purge_activity_logs';

    public function label(): string
    {
        return match($this) {
            self::PushClearpass          => 'Push ClearPass Endpoints',
            self::PullClearpassLogs      => 'Pull ClearPass Auth Logs',
            self::PurgeClearpassAuthLogs => 'Purge ClearPass Auth Logs',
            self::PushDns                => 'Push DNS Configs',
            self::PushDhcp               => 'Push DHCP Configs',
            self::PurgeLeases            => 'Purge DHCP Lease Logs',
            self::PurgePushLogs          => 'Purge Push Logs',
            self::PurgeDeletedHosts      => 'Purge Deleted Hosts',
            self::DatabaseBackup         => 'Database Backup',
            self::PullSnipeIt            => 'Pull Snipe-IT Assets',
            self::PurgeActivityLogs      => 'Purge Activity Logs',
        };
    }

    public function description(): string
    {
        return match($this) {
            self::PushClearpass          => 'Syncs interface data to the endpoint repository on all configured ClearPass servers. Creates, updates, and removes only endpoints managed by DashDDI.',
            self::PullClearpassLogs      => 'Pulls authentication session logs from all configured ClearPass servers using the REST API. On first run, imports the last 24 hours. Subsequent runs pick up where the previous left off.',
            self::PurgeClearpassAuthLogs => 'Deletes ClearPass authentication log entries older than the retention period configured in Application Settings (default 90 days).',
            self::PushDns                => 'Generates BIND zone files and dashddi.conf, then deploys them to all configured DNS servers.',
            self::PushDhcp               => 'Generates DHCP subnet configuration and deploys it to all configured DHCP servers.',
            self::PurgeLeases            => 'Deletes DHCP lease log entries that exceed each subnet\'s configured retention period. A default retention period (for subnets with no per-subnet setting and unmatched leases) can be configured in Application Settings.',
            self::PurgePushLogs          => 'Deletes DNS/DHCP push log entries older than the retention period configured in Application Settings (default 30 days).',
            self::PurgeDeletedHosts      => 'Hard-deletes hosts and interfaces that were soft-deleted more than the configured retention period ago (default 90 days). Configured in Application Settings.',
            self::DatabaseBackup         => 'Creates a database backup using the destination and options configured in Backup Settings.',
            self::PullSnipeIt            => 'Fetches assets from all configured Snipe-IT servers. Creates a host for each asset that has a MAC address in any configured custom field, updates existing hosts, and removes hosts whose assets have been deleted or archived.',
            self::PurgeActivityLogs      => 'Deletes activity log entries older than the retention period configured in Application Settings (default 90 days).',
        };
    }

    /** Console command and arguments to execute for this task. */
    public function consoleCommand(): string
    {
        return match($this) {
            self::PushClearpass          => 'app:push-clearpass',
            self::PullClearpassLogs      => 'app:pull-clearpass-logs',
            self::PurgeClearpassAuthLogs => 'app:purge-clearpass-auth-logs',
            self::PushDns                => 'app:generate-dns-config --deploy',
            self::PushDhcp               => 'app:generate-dhcp-config --reload',
            self::PurgeLeases            => 'app:purge-dhcp-leases',
            self::PurgePushLogs          => 'app:purge-push-logs',
            self::PurgeDeletedHosts      => 'app:purge-deleted-hosts',
            self::DatabaseBackup         => 'app:database:backup',
            self::PullSnipeIt            => 'app:pull-snipe-it',
            self::PurgeActivityLogs      => 'app:purge-activity-logs',
        };
    }

    public function defaultCron(): string
    {
        return match($this) {
            self::PushClearpass          => '0 2 * * *',
            self::PullClearpassLogs      => '*/15 * * * *',
            self::PurgeClearpassAuthLogs => '0 3 * * 0',
            self::PushDns                => '0 2 * * *',
            self::PushDhcp               => '0 2 * * *',
            self::PurgeLeases            => '0 3 * * 0',
            self::PurgePushLogs          => '0 3 * * 0',
            self::PurgeDeletedHosts      => '0 3 * * 0',
            self::DatabaseBackup         => '0 2 * * *',
            self::PullSnipeIt            => '0 3 * * *',
            self::PurgeActivityLogs      => '0 3 * * 0',
        };
    }
}
