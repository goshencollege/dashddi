# DashDDI

DashDDI is a web-based DNS, DHCP, and IP Address Management (IPAM) system. It provides a unified interface for managing network infrastructure including subnets, hosts, DNS zones and records, DHCP servers, VLANs, and VRFs — with SAML-based authentication, API token access, encrypted backups, and real-time push notifications.

## Features

- **IP Address Management** — Track subnets (IPv4/IPv6), address blocks, VLANs, VRFs, hosts, and network interfaces; collapsible tree view with bulk delete, bulk tag, and bulk edit (reassign subnet, auto-assign IPv4/IPv6) for both hosts and interfaces
- **DNS Management** — Manage domains, DNS records, views, DNSSEC policies, and KSK rollover; generate and deploy BIND zone files
- **DHCP Management** — Configure DHCP servers, generate Kea DHCP configs, and track leases
- **Scheduled Tasks** — Cron-based task execution (evaluated in configured timezone)
- **Backup & Restore** — Encrypted database backups with configurable retention and scheduling
- **Push Notifications** — Real-time push messages on entity changes with push log history
- **SAML Authentication** — Configurable SAML 2.0 identity provider integration
- **API Access** — Token-based API with granular route-level permissions
- **Certbot Plugin** — `certbot-dns-dashddi` plugin for DNS-01 ACME challenges via Let's Encrypt

## Tech Stack

- **PHP 8.3** / **Symfony 7.4**
- **MySQL 8.0**
- **Nginx** (reverse proxy with SSL/TLS)
- **Docker & Docker Compose**
- **Symfony Messenger** (async message queue / worker)

## Deployment

### Prerequisites

- Docker and Docker Compose
- `openssl` (for generating secrets and self-signed certificates)

### Quick Start

Clone the repository and run the setup script:

```bash
git clone <repo-url> dashddi
cd dashddi
./setup.sh
```

For a development environment:

```bash
./setup.sh --dev
```

The setup script is an interactive wizard that will:

1. Generate `APP_SECRET` and `APP_ENCRYPTION_KEY`
2. Prompt for your fully qualified domain name and build the base URL
3. Configure SSL — choose one of:
   - **Self-signed certificate** (10-year validity, suitable for internal use)
   - **Let's Encrypt** via certbot (requires public DNS)
   - **User-provided certificate**
4. Configure the database — choose one of:
   - **Container-managed MySQL** with auto-generated credentials
   - **External MySQL server**
5. Generate `docker-compose.prod.yml` (or `docker-compose.dev.yml`) with all configured values
6. Build and start the containers
7. Run database migrations
8. Warm the Symfony cache
9. Optionally import SAML IdP metadata to enable authentication

### Starting and Stopping

After initial setup, use the generated compose file:

```bash
# Start
docker compose -f docker-compose.prod.yml up -d

# Stop
docker compose -f docker-compose.prod.yml down

# View logs
docker compose -f docker-compose.prod.yml logs -f
```

### Containers

| Service          | Description                                                      |
|------------------|------------------------------------------------------------------|
| `app`            | PHP 8.3-FPM application                                          |
| `worker_priority`| Messenger consumer for fast push messages (`async_priority`)     |
| `worker_bulk`    | Messenger consumer for long-running jobs (`async_bulk`); also monitors `async_priority` when idle |
| `nginx`          | Nginx reverse proxy (HTTP/HTTPS)                                 |
| `db`             | MySQL 8.0 database                                               |

### Running Console Commands

All Symfony console commands must be run inside the `app` container:

```bash
docker compose exec app bin/console <command>
```

Common commands:

```bash
# Run database migrations
docker compose exec app bin/console doctrine:migrations:migrate

# Warm the cache
docker compose exec app bin/console cache:warmup

# Import SAML IdP metadata from a URL or file
docker compose exec app bin/console app:saml:import-metadata

# Generate DNS config
docker compose exec app bin/console app:generate-dns-config

# Backup the database
docker compose exec app bin/console app:database:backup

# Restore a database backup
docker compose exec app bin/console app:database:restore
```

### Environment Variables

Key variables written to the generated compose file by `setup.sh`:

| Variable               | Description                                              |
|------------------------|----------------------------------------------------------|
| `APP_ENV`              | `prod` or `dev`                                          |
| `APP_SECRET`           | Symfony application secret (auto-generated)              |
| `APP_ENCRYPTION_KEY`   | Base64-encoded key for encrypting sensitive data         |
| `DATABASE_URL`         | MySQL DSN (`mysql://user:pass@host:3306/dbname`)         |
| `DEFAULT_URI`          | Base URL of the application (e.g. `https://dashddi.example.com`) |
| `MESSENGER_TRANSPORT_DSN` | Doctrine-backed async message queue DSN              |

### Updating

```bash
git pull
docker compose -f docker-compose.prod.yml build app worker_priority worker_bulk
docker compose -f docker-compose.prod.yml up -d
docker compose exec app bin/console doctrine:migrations:migrate
docker compose exec app bin/console cache:warmup
```

## Development

### Dev stack

A `docker-compose.dev.yml` is generated by `setup.sh --dev` (or `setup.sh` with the dev option). A `Makefile` wraps the most common operations:

```bash
make up          # start containers
make down        # stop containers
make bash        # shell into the app container
make migrate     # run Doctrine migrations
make cc          # clear Symfony cache
make logs        # tail all container logs
make reset       # wipe volumes, rebuild, migrate, load fixtures (full reset)
```

PHP is only available inside the containers — never run `php` or `bin/console` directly on the host.

### Testing

The test suite has 204 tests: 91 unit tests and 113 functional (HTTP-level) tests.

```bash
make test-setup  # first-time only: create dashddi_test DB, run migrations, load fixtures
make test        # run all 204 tests
```

Functional tests run against a real `dashddi_test` MySQL database. Each test is wrapped in a DBAL transaction that is rolled back after the test, so the database stays clean between runs without reloading fixtures.

## Integrations

DashDDI connects to several external systems for configuration deployment, asset sync, and network access control. Each integration has its own setup guide:

| Integration | Description |
|---|---|
| [DNS / BIND](docs/dns-bind.md) | Generate and deploy BIND zone files to DNS servers via SSH |
| [DHCP / Kea](docs/dhcp-kea.md) | Generate and deploy Kea DHCP config via SSH; receive lease notifications via webhook |
| [Aruba CX Switch](docs/aruba-cx-switch.md) | Query port info and perform port actions (bounce, reauthenticate, POE) |
| [Snipe-IT](docs/snipe-it.md) | Pull asset and MAC address data from Snipe-IT to populate hosts and interfaces |
| [ClearPass](docs/clearpass.md) | Push managed endpoints to ClearPass and pull RADIUS authentication logs |

DashDDI also exposes a REST API for programmatic access to all resources. Full API documentation — including all endpoints, request/response schemas, and authentication details — is available within the app at `/api-docs`.

## Certbot DNS Plugin

The `certbot-dns-dashddi` directory contains a Python plugin that enables Let's Encrypt DNS-01 challenge automation through DashDDI's API. See [`certbot-dns-dashddi/README.md`](certbot-dns-dashddi/README.md) for installation and usage instructions.

## License

[AGPL-3.0-or-later](LICENSE)
