# certbot-dns-dashddi

A Certbot DNS authenticator plugin that uses the [DashDDI](https://github.com/goshencollege/dashddi) host self-service API to perform DNS-01 challenges. This allows you to obtain and renew certificates automatically on any host managed by DashDDI without exposing broad API credentials.

## Requirements

- The host must be registered in DashDDI with at least one network interface whose IP address matches the machine running Certbot.
- The host's A, AAAA, or CNAME record must exist in DashDDI and be in a domain with at least one **public** DNS view.
- A host-scoped token must be generated from the host detail page (see Setup below).

## Installation

Certbot and the plugin must share the same Python environment. On modern Debian/Ubuntu systems, install both into a virtualenv to avoid the `externally-managed-environment` error:

```bash
apt install -y python3-venv
python3 -m venv /opt/certbot
/opt/certbot/bin/pip install certbot
/opt/certbot/bin/pip install git+https://github.com/goshencollege/dashddi/#subdirectory=certbot-dns-dashddi
ln -s /opt/certbot/bin/certbot /usr/local/bin/certbot
```

Verify the plugin is detected:

```bash
certbot plugins
```

You should see `dns-dashddi` in the list.

> **Note:** If you later upgrade Certbot (`/opt/certbot/bin/pip install --upgrade certbot`), re-run the plugin install line to keep the two in sync.

> **Windows:** Use [win-acme-dashddi](../win-acme-dashddi/) instead. win-acme integrates natively with the Windows Certificate Store and does not require Python.

## Setup

### 1. Generate a host-scoped token

In the DashDDI UI, navigate to **Hosts**, open the host record for this machine, and click **Generate Token** in the Host API Token card. Copy the displayed token — it is shown only once.

The token is automatically restricted to requests originating from this host's IP addresses. No route permissions need to be configured.

### 2. Create a credentials file

```bash
cp dashddi.ini.example /etc/letsencrypt/dashddi.ini
chmod 600 /etc/letsencrypt/dashddi.ini
```

Edit `/etc/letsencrypt/dashddi.ini`:

```ini
dns_dashddi_url = https://dashddi.example.com
dns_dashddi_token = your-host-scoped-token-here
```

### 3. Request a certificate

```bash
certbot certonly \
  --authenticator dns-dashddi \
  --dns-dashddi-credentials /etc/letsencrypt/dashddi.ini \
  -d myhost.example.com
```

For wildcard certificates:

```bash
certbot certonly \
  --authenticator dns-dashddi \
  --dns-dashddi-credentials /etc/letsencrypt/dashddi.ini \
  -d '*.example.com'
```

## Configuration options

| Option | Description | Default |
|--------|-------------|---------|
| `--dns-dashddi-credentials` | Path to the credentials INI file | *(required)* |
| `--dns-dashddi-propagation-seconds` | Seconds to wait for DNS propagation before asking the CA to validate | `30` |

## How it works

1. Certbot asks the plugin to prove control of a domain by placing a TXT record at `_acme-challenge.<domain>`.
2. The plugin strips the `_acme-challenge.` prefix to recover the source FQDN (e.g. `myhost.example.com`) and calls `POST /api/self/dns-challenge` with the FQDN and validation token.
3. DashDDI looks up the matching DNS record on the host, creates the `_acme-challenge.*` TXT record, and assigns it to all views the domain is part of — ensuring Let's Encrypt can reach it via the public view even if the host's A record is internal-only.
4. After the CA validates the challenge, the plugin calls `DELETE /api/self/dns-challenge` with the same FQDN and token to remove the record.

## Automatic FQDN discovery with `dashddi-certbot`

The package installs a `dashddi-certbot` helper that queries `GET /api/self/host` to discover all A/AAAA FQDNs registered to this host in DashDDI, then runs `certbot certonly` for all of them in a single SAN certificate request.

```bash
dashddi-certbot --credentials /etc/letsencrypt/dashddi.ini
```

Extra arguments after `--` are passed through to certbot:

```bash
dashddi-certbot --credentials /etc/letsencrypt/dashddi.ini \
  -- --dry-run --dns-dashddi-propagation-seconds 60
```

This is the recommended way to set up automatic renewal — add it to a systemd timer or cron job and it will always request certs for exactly the FQDNs DashDDI knows about for this host. A, AAAA, and CNAME record FQDNs in domains with a public view are included.

## Ansible role

An Ansible role is included at `ansible/roles/dashddi_certbot/` that automates the full setup: token generation, plugin installation, credentials file, and systemd renewal timer.

An example playbook is at `ansible/certbot.yml`.

### Role variables

| Variable | Default | Description |
|---|---|---|
| `dashddi_url` | *(required)* | Base URL of your DashDDI instance |
| `dashddi_admin_token` | *(required)* | General-purpose token with `api_hosts_index` and `api_hosts_token_generate` route permissions |
| `dashddi_host_name` | `{{ inventory_hostname }}` | Name of the host in DashDDI (must match exactly) |
| `dashddi_credentials_path` | `/etc/letsencrypt/dashddi.ini` | Where to write the credentials file |
| `dashddi_venv_path` | `/opt/certbot` | Virtualenv path for certbot and the plugin |
| `dashddi_plugin_source` | `git+https://github.com/goshencollege/dashddi/…` | pip-installable source for the plugin |
| `dashddi_propagation_seconds` | `30` | DNS propagation wait passed to certbot |
| `dashddi_force_token_regenerate` | `false` | Set to `true` to revoke and replace the existing host token |
| `dashddi_deploy_hook` | `""` | Optional command passed to certbot as `--deploy-hook` after each successful renewal. For the DashDDI server itself, set this to the absolute path of `docker/certbot-deploy.sh` in the project directory (e.g. `/opt/dashddi/docker/certbot-deploy.sh`) to automatically push the new cert into Docker. Leave empty for other hosts. |

### Usage

Install the role's dependencies (if any) and run the example playbook against your target hosts:

```bash
ansible-playbook -i inventory ansible/certbot.yml
```

Set `dashddi_url` and `dashddi_admin_token` in your inventory `group_vars` or `host_vars`, or pass them on the command line:

```bash
ansible-playbook -i inventory ansible/certbot.yml \
  -e dashddi_url=https://dashddi.example.com \
  -e @vault.yml          # encrypted file containing dashddi_admin_token
```

To force token regeneration on a host (revokes the existing token and writes a new credentials file):

```bash
ansible-playbook -i inventory ansible/certbot.yml \
  -e dashddi_force_token_regenerate=true \
  --limit web01.example.com
```

The role is idempotent: if the credentials file already exists and `dashddi_force_token_regenerate` is false, token generation and the credentials file write are skipped. Installation and systemd unit deployment always run.

## Troubleshooting

**`401 Unauthorized`**

The token is invalid, or the request is coming from an IP address not assigned to the host in DashDDI. Verify the token matches what was generated on the host detail page and that the host's interfaces include the machine's current IP.

**`403 Forbidden` — "FQDN does not belong to this host"**

The FQDN being certified does not have a DNS record linked to this host's interfaces in DashDDI. Add an A or AAAA record for the hostname under the correct domain before running Certbot.

**`422 Unprocessable Entity` — "no public views"**

The domain containing the hostname has DNS views, but none are marked as public. In DashDDI, go to **DNS → Views**, edit the view that is reachable from the internet, and enable the **Public view** checkbox.

**Validation fails despite record being created**

Increase the propagation wait time:

```bash
certbot certonly \
  --authenticator dns-dashddi \
  --dns-dashddi-credentials /etc/letsencrypt/dashddi.ini \
  --dns-dashddi-propagation-seconds 60 \
  -d myhost.example.com
```

## Upgrading from v1

Version 2.0 replaces the general-purpose token with a host-scoped token and drops the `dns_dashddi_view_ids` config option. To upgrade:

1. Generate a host-scoped token from the host detail page in DashDDI.
2. Replace `dns_dashddi_token` in your credentials file with the new token.
3. Remove the `dns_dashddi_view_ids` line if present — view assignment is now automatic.
4. Reinstall the plugin: `/opt/certbot/bin/pip install --upgrade git+https://github.com/goshencollege/dashddi/#subdirectory=certbot-dns-dashddi`
