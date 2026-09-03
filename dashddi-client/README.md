# dashddi-client

A DashDDI host self-service client for Linux. Provides a Certbot DNS-01 authenticator
plugin that uses the [DashDDI](https://github.com/goshencollege/dashddi) host
self-service API, plus a `dashddi` CLI that discovers a host's FQDNs, requests
certificates, and can publish CAA/HTTPS records after issuance — all without exposing
broad API credentials to the host.

> **Windows:** use [dashddi-client-windows](../dashddi-client-windows/) instead. It
> integrates natively with the Windows Certificate Store and does not require Python.

## Requirements

- The host must be registered in DashDDI with at least one network interface whose IP
  address matches the machine running this client.
- The host's A, AAAA, or CNAME record must exist in DashDDI and be in a domain with at
  least one **public** DNS view (or a public parent domain).
- A host-scoped token must be generated from the host detail page (see Setup below).

## Installation

Certbot and the plugin must share the same Python environment. On modern Debian/Ubuntu
systems, install both into a virtualenv to avoid the `externally-managed-environment`
error:

```bash
apt install -y python3-venv
python3 -m venv /opt/certbot
/opt/certbot/bin/pip install certbot
/opt/certbot/bin/pip install git+https://github.com/goshencollege/dashddi/#subdirectory=dashddi-client
ln -s /opt/certbot/bin/certbot /usr/local/bin/certbot
ln -s /opt/certbot/bin/dashddi /usr/local/bin/dashddi
```

Verify the plugin is detected:

```bash
certbot plugins
```

You should see `dns-dashddi` in the list.

> **Note:** If you later upgrade Certbot (`/opt/certbot/bin/pip install --upgrade certbot`),
> re-run the plugin install line to keep the two in sync.

## Setup

### 1. Generate a host-scoped token

In the DashDDI UI, navigate to **Hosts**, open the host record for this machine, and
click **Generate Token** in the Host API Token card. Copy the displayed token — it is
shown only once.

The token is automatically restricted to requests originating from this host's IP
addresses. No route permissions need to be configured.

### 2. Create a credentials file

The easiest way is to just skip this step: `dashddi cert` (see below) prompts for the
DashDDI URL, token, ACME subscriber agreement, and email, and writes the file for you —
at `/etc/dashddi/dashddi.ini` by default — the first time it's run against a location
with no file yet.

To create it manually instead:

```bash
mkdir -p /etc/dashddi
cp dashddi.ini.example /etc/dashddi/dashddi.ini
chmod 600 /etc/dashddi/dashddi.ini
```

Edit `/etc/dashddi/dashddi.ini`:

```ini
dns_dashddi_url = https://dashddi.example.com
dns_dashddi_token = your-host-scoped-token-here
dns_dashddi_agree_tos = true
dns_dashddi_email = admin@example.com
```

`dns_dashddi_agree_tos` records agreement to your ACME CA's subscriber agreement
(Let's Encrypt: https://letsencrypt.org/repository/) and is required — certbot runs
with `--non-interactive` and refuses to issue without it. `dns_dashddi_email` is
optional; omit it and `dashddi cert` passes `--register-unsafely-without-email` instead.

### 3. Request a certificate

```bash
certbot certonly \
  --authenticator dns-dashddi \
  --dns-dashddi-credentials /etc/dashddi/dashddi.ini \
  --agree-tos --email admin@example.com \
  -d myhost.example.com
```

For wildcard certificates:

```bash
certbot certonly \
  --authenticator dns-dashddi \
  --dns-dashddi-credentials /etc/dashddi/dashddi.ini \
  --agree-tos --email admin@example.com \
  -d '*.example.com'
```

(`--agree-tos` and `--email`/`--register-unsafely-without-email` are certbot's own
non-interactive requirements, not specific to the dns-dashddi plugin. `dashddi cert`,
below, handles these for you.)

## Configuration options

| Option | Description | Default |
|--------|-------------|---------|
| `--dns-dashddi-credentials` | Path to the credentials INI file | *(required)* |
| `--dns-dashddi-propagation-seconds` | Seconds to wait for DNS propagation before asking the CA to validate | `30` |

## How it works

1. Certbot asks the plugin to prove control of a domain by placing a TXT record at
   `_acme-challenge.<domain>`.
2. The plugin strips the `_acme-challenge.` prefix to recover the source FQDN (e.g.
   `myhost.example.com`) and calls `POST /api/self/dns-challenge` with the FQDN and
   validation token.
3. DashDDI looks up the matching DNS record on the host — an exact match, or a wildcard
   record covering the requested name (e.g. a `*.example.com` record covers
   `foo.example.com`) — creates the `_acme-challenge.*` TXT record, and assigns it to
   all views the domain is part of, ensuring Let's Encrypt can reach it via the public
   view even if the host's A record is internal-only.
4. After the CA validates the challenge, the plugin calls `DELETE /api/self/dns-challenge`
   with the same FQDN and token to remove the record.

## The `dashddi` CLI

The package installs a `dashddi` command. Its `cert` subcommand queries
`GET /api/self/host` to discover all A/AAAA/CNAME FQDNs registered to this host in
DashDDI, then runs `certbot certonly` for all of them in a single SAN certificate
request:

```bash
dashddi cert
```

`--credentials` is optional and defaults to `/etc/dashddi/dashddi.ini`; pass it to use
a different location:

```bash
dashddi cert --credentials /path/to/dashddi.ini
```

If the credentials file doesn't exist yet at whichever path is in effect, `dashddi cert`
prompts for the DashDDI URL, host-scoped token, ACME subscriber agreement, and an
optional email address, and writes it (mode 600) before continuing — no separate setup
step required. This only happens in an interactive terminal; a missing file with no TTY
attached (e.g. a scheduled renewal run before initial setup) is a hard error instead.
The same prompt runs once against an existing credentials file that predates
`dns_dashddi_agree_tos` (e.g. created by hand from `dashddi.ini.example`, or written by
an older `dashddi-client`), and the answers are appended to the file so later runs —
including unattended renewals — never need to prompt again.

Extra arguments after `--` are passed through to certbot:

```bash
dashddi cert -- --dry-run --dns-dashddi-propagation-seconds 60
```

This is the recommended way to set up automatic renewal. It will always request certs
for exactly the FQDNs DashDDI knows about for this host — A, AAAA, and CNAME record
FQDNs in domains with a public view are included.

### Automatic renewal schedule

After a successful certificate request, `dashddi cert` automatically installs and
enables a systemd timer (`dashddi.timer`, twice daily) that reruns the exact same
command — same credentials file, `--names`/`--caa`/`--https`/`--https-value` flags, and
any extra certbot arguments after `--`. This means running `dashddi cert` once by hand
is enough to set up ongoing renewal; you don't need to configure a timer or cron job
yourself.

An existing `/etc/systemd/system/dashddi.timer` (e.g. one deployed by the Ansible role,
or from a previous run) is never overwritten, so hand-customized schedules are left
alone. Setup requires running as root and a systemd host; on a non-systemd host, or when
run unprivileged (e.g. `--dry-run` testing), it's silently skipped with a note on
stderr.

Pass `--no-schedule` to skip this entirely:

```bash
dashddi cert --credentials /etc/dashddi/dashddi.ini --no-schedule
```

If you're using the Ansible role, it deploys its own `dashddi.service`/`dashddi.timer`
(with support for a deploy hook — see below), so no action is needed there; the CLI's
own schedule setup will find the timer already exists and step aside.

### Requesting a subset of names, or names covered by a wildcard

By default `dashddi cert` certifies every publicly-reachable FQDN the host owns. To
certify an explicit list instead — a subset of those names, or concrete names not
present as their own record but covered by a wildcard record — set `dns_dashddi_names`
in the credentials file:

```ini
dns_dashddi_names = foo.example.com,bar.example.com
```

or pass `--names` on the command line (takes precedence over the credentials file):

```bash
dashddi cert --credentials /etc/dashddi/dashddi.ini \
  --names foo.example.com,bar.example.com
```

When set, auto-discovery is skipped entirely and exactly this list is requested. DashDDI
still enforces ownership per name at DNS-01 validation time — a name matched only by a
wildcard record (e.g. `foo.example.com` matched by a `*.example.com` A record) is
accepted; an unrelated name is rejected with a `403`.

### Publishing CAA/HTTPS records after issuance

**CAA** — set `dns_dashddi_caa = true` in the credentials file, or pass `--caa` on the
command line, to have `dashddi cert` create or update a CAA record authorizing the
issuing CA at each certified FQDN after every successful (re)issuance:

```ini
dns_dashddi_caa = true
```

```bash
dashddi cert --credentials /etc/dashddi/dashddi.ini --caa
```

There's no CA to specify — it's auto-detected from the ACME server certbot was told to
use (Let's Encrypt by default; if you pass `--server <url>` after `--`, the CA is
derived from that URL's registrable domain instead, e.g. `zerossl.com`).

**HTTPS** — content isn't derivable from the issuance the way the CA is, but you can
still just turn it on and get a generally-safe default (`1 . alpn=h2`, advertising
HTTP/2 — the default deliberately omits `h3`/HTTP/3, since that requires explicit QUIC
support most servers don't have out of the box; if yours does, add it via
`dns_dashddi_https_value` below):

```ini
dns_dashddi_https = true
```

```bash
dashddi cert --credentials /etc/dashddi/dashddi.ini --https
```

Or provide an explicit value instead (implies enabling it, on either platform):

```ini
dns_dashddi_https_value = 1 . alpn=h2,h3
```

```bash
dashddi cert --credentials /etc/dashddi/dashddi.ini --https-value '1 . alpn=h2,h3'
```

Either one calls `PUT /api/self/records` once per FQDN. Each call is an idempotent
upsert matched by hostname + domain + type, so re-running it on every renewal is safe —
an unchanged value is left alone, a changed value is updated in place. A failure
publishing one of these records is logged as a warning and does not fail the overall
run; certificate issuance succeeding is the primary outcome.

**Limitation:** this manages at most one CAA record and one HTTPS record per FQDN. If
you need more than one CAA policy line at a name (e.g. both `issue` and `issuewild`),
manage the extra ones directly in the DashDDI DNS UI — mixing a manually-created record
with a client-managed one at the same name+type is not supported, since the client will
treat whichever one it finds as "the managed one" and overwrite it.

## Ansible role

An Ansible role is included at `ansible/roles/dashddi_client/` that automates the full
setup: token generation, plugin installation, credentials file, and systemd renewal
timer.

An example playbook is at `ansible/dashddi.yml`.

### Role variables

| Variable | Default | Description |
|---|---|---|
| `dashddi_url` | *(required)* | Base URL of your DashDDI instance |
| `dashddi_admin_token` | *(required)* | General-purpose token with `api_hosts_index` and `api_hosts_token_generate` route permissions |
| `dashddi_host_name` | `{{ inventory_hostname }}` | Name of the host in DashDDI (must match exactly) |
| `dashddi_credentials_path` | `/etc/dashddi/dashddi.ini` | Where to write the credentials file |
| `dashddi_venv_path` | `/opt/certbot` | Virtualenv path for certbot and the plugin |
| `dashddi_plugin_source` | `git+https://github.com/goshencollege/dashddi/…` | pip-installable source for the plugin |
| `dashddi_propagation_seconds` | `30` | DNS propagation wait passed to certbot |
| `dashddi_force_token_regenerate` | `false` | Set to `true` to revoke and replace the existing host token |
| `dashddi_deploy_hook` | `""` | Optional command passed to certbot as `--deploy-hook` after each successful renewal. For the DashDDI server itself, set this to the absolute path of `docker/certbot-deploy.sh` in the project directory (e.g. `/opt/dashddi/docker/certbot-deploy.sh`) to automatically push the new cert into Docker. Leave empty for other hosts. |
| `dashddi_names` | `""` | Optional comma-separated explicit FQDN list — see "Requesting a subset of names" above. Leave empty for the default auto-discovery behaviour. |
| `dashddi_caa` | `false` | Set to `true` to publish a CAA record (CA auto-detected) at each issued FQDN |
| `dashddi_https` | `false` | Set to `true` to publish an HTTPS record (default value) at each issued FQDN |
| `dashddi_https_value` | `""` | Optional explicit HTTPS record value, e.g. `1 . alpn=h2,h3` (implies `dashddi_https`) |

### Usage

Install the role's dependencies (if any) and run the example playbook against your
target hosts:

```bash
ansible-playbook -i inventory ansible/dashddi.yml
```

Set `dashddi_url` and `dashddi_admin_token` in your inventory `group_vars` or
`host_vars`, or pass them on the command line:

```bash
ansible-playbook -i inventory ansible/dashddi.yml \
  -e dashddi_url=https://dashddi.example.com \
  -e @vault.yml          # encrypted file containing dashddi_admin_token
```

To force token regeneration on a host (revokes the existing token and writes a new
credentials file):

```bash
ansible-playbook -i inventory ansible/dashddi.yml \
  -e dashddi_force_token_regenerate=true \
  --limit web01.example.com
```

The role is idempotent: if the credentials file already exists and
`dashddi_force_token_regenerate` is false, token generation and the credentials file
write are skipped. Installation and systemd unit deployment always run.

## Troubleshooting

**`401 Unauthorized`**

The token is invalid, or the request is coming from an IP address not assigned to the
host in DashDDI. Verify the token matches what was generated on the host detail page
and that the host's interfaces include the machine's current IP.

**`403 Forbidden` — "FQDN does not belong to this host"**

The FQDN being certified does not have a DNS record linked to this host's interfaces in
DashDDI (directly, or via a wildcard record covering it). Add an A or AAAA record for
the hostname under the correct domain before running Certbot.

**`422 Unprocessable Entity` — "no public views"**

The domain containing the hostname has DNS views, but none are marked as public. In
DashDDI, go to **DNS → Views**, edit the view that is reachable from the internet, and
enable the **Public view** checkbox.

**Validation fails despite record being created**

Increase the propagation wait time:

```bash
certbot certonly \
  --authenticator dns-dashddi \
  --dns-dashddi-credentials /etc/dashddi/dashddi.ini \
  --dns-dashddi-propagation-seconds 60 \
  -d myhost.example.com
```

## Upgrading from certbot-dns-dashddi v2

Version 3.0 renames the project from `certbot-dns-dashddi` to `dashddi-client`, and the
`dashddi-certbot` command to `dashddi cert`, since the client is growing beyond pure
certificate issuance (name selection, CAA/HTTPS publishing). To upgrade an existing
host:

1. Reinstall the plugin from the new subdirectory path:
   ```bash
   /opt/certbot/bin/pip install --upgrade git+https://github.com/goshencollege/dashddi/#subdirectory=dashddi-client
   ```
2. Re-point the CLI symlink:
   ```bash
   rm -f /usr/local/bin/dashddi-certbot
   ln -s /opt/certbot/bin/dashddi /usr/local/bin/dashddi
   ```
3. If you're using the systemd timer, replace the old units with the renamed ones (or
   re-run the Ansible role, which does this for you):
   ```bash
   systemctl disable --now dashddi-certbot.timer
   rm -f /etc/systemd/system/dashddi-certbot.service /etc/systemd/system/dashddi-certbot.timer
   # deploy dashddi.service / dashddi.timer (see ansible/roles/dashddi_client/templates/)
   systemctl enable --now dashddi.timer
   ```
4. If you're using the Ansible role, update your playbook to point at
   `ansible/dashddi.yml` and the `dashddi_client` role instead of the old
   `certbot.yml` / `dashddi_certbot`.

**Nothing else needs to change, and no certificates need to be re-issued.** The
Certbot plugin's authenticator id (`dns-dashddi`, the value used with
`--authenticator`) is unchanged, so every already-issued certificate's
`/etc/letsencrypt/renewal/*.conf` keeps working and keeps renewing exactly as before —
even before you complete the steps above.

### Credentials file location

The credentials file now defaults to `/etc/dashddi/dashddi.ini` instead of
`/etc/letsencrypt/dashddi.ini` — now that the client does more than drive Certbot (name
discovery, CAA/HTTPS publishing, renewal scheduling), it no longer makes sense to live
under Certbot's own config directory. This is purely a documentation/default change:
`--dns-dashddi-credentials` / `--credentials` is just a path, so nothing breaks if an
existing host keeps its file at the old location. To move one over:

```bash
mkdir -p /etc/dashddi
mv /etc/letsencrypt/dashddi.ini /etc/dashddi/dashddi.ini
```

Then update wherever the path is referenced: any existing `dashddi.timer`/cron entry,
and `/etc/letsencrypt/renewal/*.conf` for the `dns_dashddi_credentials` value Certbot
stores from the original issuance. Ansible-managed hosts pick up the new default path
automatically on the next run.
