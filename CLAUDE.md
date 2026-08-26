# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Git workflow

Always use **merge commits** when merging pull requests — never squash. Use `gh pr merge <number> --merge --delete-branch`.


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
make test-setup  # create/migrate/seed the dashddi_test database (run once before first test run)
make test        # run the full test suite (91 unit + 113 functional)
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
- **`async_priority`** — fast push messages (DNS, DHCP, ClearPass single-server) plus manually-triggered pulls (Snipe-IT pull, ClearPass log pull). Consumed by the `worker_priority` container.
- **`async_bulk`** — long-running jobs (full ClearPass sync, arbitrary scheduled tasks run via "Run Now"). Consumed by `worker_bulk`, which also monitors `async_priority` when idle.

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

### MAC vendor (OUI) lookup
`OuiLookupService` resolves a MAC address's manufacturer from `resources/oui/oui-database.php`, a generated PHP array (`OUI prefix => vendor name`) built from the public IEEE MA-L registry. It's exposed to templates via the `mac_vendor()` Twig function (`src/Twig/AppExtension.php`) and shown as a `title` hover attribute wherever MAC addresses are rendered. Locally administered addresses (U/L bit set) are reported as "Locally administered (randomized)" rather than looked up. Regenerate the dataset with `app:oui:update`, which re-downloads the registry — no network access happens at request time.

## Console commands

All commands run inside the `app` container:

```bash
app:saml:import-metadata          # import IdP XML from URL or file path
app:generate-encryption-key       # generate a new APP_ENCRYPTION_KEY value
app:generate-dns-config           # generate BIND zone files (--deploy to push)
app:generate-dhcp-config          # generate Kea config (--reload to deploy)
app:database:backup               # encrypted database backup
app:database:restore              # restore from backup
app:push-clearpass                # push interfaces to ClearPass NAC
app:pull-clearpass-logs           # pull auth logs from ClearPass
app:pull-snipe-it                 # sync assets from Snipe-IT
app:run-scheduled-tasks           # execute all due scheduled tasks
app:purge-clearpass-auth-logs     # purge old ClearPass auth logs
app:purge-switch-port-logs        # purge old switch-port attachment logs
app:purge-push-logs               # purge old push logs
app:purge-dhcp-leases             # purge expired DHCP leases
app:purge-deleted-hosts           # hard-delete soft-deleted records past retention
app:oui:update                    # refresh the bundled MAC vendor (IEEE OUI) database
```

## Database migrations

```bash
# Generate a migration after changing an entity
docker compose -f docker-compose.dev.yml exec app php bin/console make:migration

# Apply migrations
make migrate
```

## Test suite

204 tests total: 91 unit tests (`tests/Unit/`) and 113 functional tests (`tests/Functional/`).

```bash
make test-setup  # first-time only: creates dashddi_test DB, runs migrations, loads fixtures
make test        # run all 182 tests
```

Both targets auto-generate `.env.test.local` (gitignored) from `.env.test.local.dist` on first run, pulling `APP_ENCRYPTION_KEY` and `DATABASE_URL` out of `docker-compose.dev.yml`. Delete `.env.test.local` and re-run to regenerate after running `setup.sh` again.

### Test structure

```
tests/Unit/
  Service/         # one test class per service
  Entity/Trait/    # tests for AuditableTrait and SoftDeletableTrait
  Validator/       # tests for custom Symfony validators
tests/Functional/
  AppWebTestCase.php          # base class: fake SAML auth, DBAL transaction isolation
  Controller/                 # HTTP-level CRUD tests for all controllers
  Api/                        # JSON API endpoint tests
```

### How functional tests work

- Each test is wrapped in a DBAL transaction that is rolled back in `tearDown`, so the `dashddi_test` database stays clean between tests — no fixture reloads needed between runs.
- A fake SAML user is injected via `KernelBrowser::loginUser()` so every request is authenticated.
- `KernelBrowser::disableReboot()` keeps one kernel and one DBAL connection for the whole test, which is required for the transaction isolation to work.
- Stateless CSRF (`SameOriginCsrfTokenManager`) is satisfied by setting `Sec-Fetch-Site: same-origin` on all test requests.

### What is covered

| File | What it tests |
|---|---|
| `Service/EncryptionServiceTest` | encrypt/decrypt round-trip, prefix detection, wrong key, corrupt data |
| `Service/ReservedTagPrefixServiceTest` | prefix matching (case-insensitive), no-match, getPrefixes |
| `Service/DnsViewResolverTest` | view intersection, null domain/subnet, isDomainUsable, reason strings |
| `Service/SshKeyServiceTest` | Ed25519 key-pair generation, public-key extraction |
| `Api/SubnetApiControllerTest` | subnet CRUD, terminal CIDR overlap (IPv4, IPv6, containment, self-edit, container exemption) |
| `Api/AddressBlockApiControllerTest` | block CRUD, intra-subnet overlap (exact, partial, contained, adjacent, cross-subnet, self-edit) |
| `Controller/SubnetControllerTest` | subnet web CRUD, terminal CIDR overlap via form, inline block mutual overlap |
| `Controller/AddressBlockControllerTest` | block web CRUD, intra-subnet overlap via form |
| `Service/IpAddressManagerTest` | available IPv4/IPv6 ranges, limit, allocation exclusion, EUI-64, IPv6-from-IPv4 |
| `Service/BindZoneFileParserTest` | A/AAAA/CNAME/MX/NS/TXT records, $ORIGIN/$TTL directives, comments, multi-line parens, inherited names |
| `Service/PushScopeServiceTest` | affectsDhcp entity types, clearpassMacsFor (iface/IP/IPv6/unrelated) |
| `Entity/Trait/SoftDeletableTraitTest` | softDelete, restore, isDeleted |
| `Entity/Trait/AuditableTraitTest` | all getters/setters, null acceptance |
| `Validator/UniqueMacAddressValidatorTest` | zero-MAC pass-through, unique/duplicate MAC, self-edit, non-interface value |

### Documentation

Whenever a feature is added or changed, update the relevant user guide page(s) in `templates/user_guide/`. The guide pages map to app areas as follows:

| Guide page | Covers |
|---|---|
| `hosts.html.twig` | Hosts, interfaces, bulk operations, deleted-host restore |
| `subnets.html.twig` | Subnets, address blocks, VRFs, import |
| `dns.html.twig` | Domains, records, views, ACLs, DNSSEC, zone import |
| `dhcp.html.twig` | DHCP config generation, pushing config, leases, ClearPass auth logs |
| `servers.html.twig` | DNS/DHCP servers, server-side setup for BIND, Kea, ClearPass, Snipe-IT, Aruba CX, REST API, Proxmox ACME, push logs, worker queue, scheduled tasks |
| `settings.html.twig` | Buildings, tags, app settings, backup/restore, API tokens, SAML, themes |

If a new section is added to a guide page, add it to the on-page nav (the sticky `list-group` in `col-lg-3`). If a new app page is created, add a contextual help link following the `btn btn-sm btn-outline-secondary` + `bi-question-circle` pattern used on all other pages.

### Rules for new features

Every new **Service**, **Validator**, or **Entity trait** must ship with a corresponding unit test file in `tests/Unit/`. The test file goes in the matching subdirectory and is named `<ClassName>Test.php`.

**Unit test conventions:**
- Extend `PHPUnit\Framework\TestCase` directly (no Symfony kernel needed for unit tests).
- Use `createStub()` for dependencies you only need to control return values on; use `createMock()` only when you need to assert a method was called a specific number of times.
- Set entity IDs via `ReflectionProperty` when testing code that calls `getId()` on unpersisted objects.
- Each test method name must read as a sentence describing the expected behaviour (e.g., `testDecryptPlaintextPassthrough`).

**What not to test in unit tests:**
- Deploy services that open SSH connections (`DnsDeployService`, `DhcpDeployService`) — these require real infrastructure.
- SAML authentication — requires an external IdP.
- Message handlers that depend on the full Symfony Messenger stack.
