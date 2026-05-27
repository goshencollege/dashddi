# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

DashDDI is a Symfony 7.4 / PHP 8.3 web application for DNS, DHCP, and IP address management. It manages subnets, hosts, DNS zones/records, DHCP servers, VLANs, and VRFs, with SAML authentication, API token access, encrypted sensitive fields, and async push notifications to external systems (BIND, Kea DHCP, ClearPass NAC, Snipe-IT).

## Running the dev stack

PHP is only available inside the Docker containers. Never run `php` or `bin/console` directly on the host.

```bash
make up          # start docker-compose.dev.yml
make down        # stop
make restart     # restart all services
make bash        # shell inside the app container
make logs        # tail all container logs
make migrate     # run Doctrine migrations
make cc          # clear Symfony cache
make db-shell    # MySQL shell
make reset       # wipe volumes, rebuild, migrate, load fixtures (full reset)
make cert        # generate a self-signed SSL cert in docker/ssl/
```

The PHP container is named `app`. For commands not in the Makefile:
```bash
docker compose -f docker-compose.dev.yml exec app php bin/console <command>
```

## Initial setup

`setup.sh` (Linux/macOS) and `setup.ps1` (Windows) are interactive wizards that prompt for environment (dev/prod), hostname, ports, SSL, and database options, then generate `docker-compose.dev.yml` or `docker-compose.prod.yml`, build images, run migrations, warm the cache, and optionally load fixtures and import SAML metadata.

## Key architecture

### Encrypted fields
`EncryptionService` uses libsodium (`sodium_crypto_secretbox`) with a base64-encoded key from `APP_ENCRYPTION_KEY`. `EncryptedFieldSubscriber` is a Doctrine listener that transparently encrypts fields before persist and decrypts them after load/persist. Encrypted values are prefixed with `enc:`. The fields covered are: `ClearpassServer.clientSecret`, `DhcpServer.sshPrivateKey/controlPassword`, `DnsServer.sshPrivateKey`, `ArubaSwitch.password/sshPrivateKey`, `SnipeItServer.apiKey`, `BackupSetting.backupPassword`. Fixture files must store these values as **plain text** — the subscriber encrypts on first persist.

### Async messaging (two queues)
Symfony Messenger with a Doctrine transport. Two separate queues:
- **`async_priority`** — fast push messages (DNS, DHCP, ClearPass single-server). Consumed by the `worker_priority` container.
- **`async_bulk`** — long-running jobs (full ClearPass sync, scheduled tasks, Snipe-IT pull). Consumed by `worker_bulk`, which also monitors `async_priority` when idle.

Failed messages go to `failed_priority` or `failed_bulk`. Message classes are in `src/Message/`, handlers in `src/MessageHandler/`.

### Scheduled tasks
`SchedulableTask` enum defines all schedulable operations (DNS push, DHCP push, backups, ClearPass sync, Snipe-IT pull, log purges). Each task specifies a default cron expression and the console command to run. `RunScheduledTasksCommand` evaluates due tasks using the timezone from `AppSetting`. Tasks are dispatched via the bulk queue.

### Entity traits
- `AuditableTrait` — adds `createdAt`, `updatedAt`, `createdBy`, `updatedBy` to most entities.
- `SoftDeletableTrait` — adds `deletedAt`; soft-deleted entities are filtered from queries by default.

### Authentication
- SAML 2.0 via OneLogin php-saml. The active `SamlProvider` entity drives configuration at runtime (`SamlSettings` loads it from the database).
- API tokens via `ApiTokenAuthenticator` (Bearer or Basic auth with token as password). Route-level permissions are stored on the `ApiToken` entity.

### DNS / DHCP deployment
`DnsConfigGenerator` produces BIND zone files; `DnsDeployService` deploys them to servers via SSH (using phpseclib). Same pattern for Kea DHCP via `DhcpConfigGenerator`. Deployment results are logged in `PushLog`.

## Console commands

All commands run inside the `app` container:

```bash
app:saml:import-idp-metadata      # import IdP XML from URL or file path
app:generate-encryption-key       # generate a new APP_ENCRYPTION_KEY value
app:dns:generate-config           # generate BIND zone files (--deploy to push)
app:dhcp:generate-config          # generate Kea config (--reload to deploy)
app:database:backup               # encrypted database backup
app:database:restore              # restore from backup
app:push-clearpass                # push interfaces to ClearPass NAC
app:pull-clearpass-logs           # pull auth logs from ClearPass
app:pull-snipe-it                 # sync assets from Snipe-IT
app:run-scheduled-tasks           # execute all due scheduled tasks
app:purge-clearpass-logs          # purge old ClearPass auth logs
app:purge-push-logs               # purge old push logs
app:purge-dhcp-leases             # purge expired DHCP leases
app:purge-deleted-entities        # hard-delete soft-deleted records past retention
```

## Database migrations

```bash
# Generate a migration after changing an entity
docker compose -f docker-compose.dev.yml exec app php bin/console make:migration

# Apply migrations
make migrate
```

## No test suite

There is currently no `tests/` directory or `phpunit.xml`. Do not reference tests when describing changes.
