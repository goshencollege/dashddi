# certbot-dns-dashddi

A Certbot DNS authenticator plugin that uses the [DashDDI](https://github.com/your-org/dashddi) DNS management API to perform DNS-01 challenges. This allows you to obtain certificates from an ACME CA without requiring the server to be publicly accessible.

## Installation

Certbot and the plugin must share the same Python environment. On modern Debian/Ubuntu systems, install both into a virtualenv to avoid the `externally-managed-environment` error:

```bash
apt install -y python3-venv
python3 -m venv /opt/certbot
/opt/certbot/bin/pip install certbot
/opt/certbot/bin/pip install git+https://github.com/davidwkdavidwk/dashddi/#subdirectory=certbot-dns-dashddi
ln -s /opt/certbot/bin/certbot /usr/local/bin/certbot
```

Verify the plugin is detected:

```bash
certbot plugins
```

You should see `dns-dashddi` in the list.

> **Note:** If you later upgrade Certbot (`/opt/certbot/bin/pip install --upgrade certbot`), re-run the plugin install line to keep the two in sync.

## Setup

### 1. Create a credentials file

Copy the example credentials file to a secure location:

```bash
cp dashddi.ini.example /etc/letsencrypt/dashddi.ini
chmod 600 /etc/letsencrypt/dashddi.ini
```

Edit `/etc/letsencrypt/dashddi.ini`:

```ini
dns_dashddi_url = https://dashddi.domain.com
dns_dashddi_token = your-api-token-here

# Optional: comma-separated DNS view IDs to add the challenge record to
# dns_dashddi_view_ids = 1
```

### 2. Generate an API token

In the DashDDI UI, go to **My Tokens** and create a new token. The token must have the following routes allowed:

- `api_domains_index`
- `api_domain_records_create`
- `api_domain_records_delete`

### 3. Request a certificate

```bash
certbot certonly \
  --authenticator dns-dashddi \
  --dns-dashddi-credentials /etc/letsencrypt/dashddi.ini \
  -d myhost.domain.com
```

## Configuration options

| Option | Description | Default |
|--------|-------------|---------|
| `--dns-dashddi-credentials` | Path to the credentials INI file | *(required)* |
| `--dns-dashddi-propagation-seconds` | Seconds to wait for DNS propagation before asking the CA to validate | `30` |

## How it works

1. Certbot asks the plugin to prove control of a domain by placing a TXT record at `_acme-challenge.<domain>`.
2. The plugin fetches all domains from the DashDDI API and finds the longest-suffix match (so `_acme-challenge.myhost.domain.com` matches domain `myhost.domain.com` in preference to `domain.com` if both exist).
3. It creates a TXT record with a TTL of 60 seconds via `POST /api/domain-records`.
4. After the CA validates the challenge, the plugin deletes the record via `DELETE /api/domain-records/{id}`.

## DNS views

If your DashDDI instance uses DNS views, add the IDs of the views that should serve the challenge record to the credentials file:

```ini
dns_dashddi_view_ids = 1,2
```

If this option is omitted, the record is created with no view association.

## Troubleshooting

**`Could not find a matching domain in DashDDI for '_acme-challenge.example.com'`**

The domain being certified does not exist in DashDDI. Add it under the Domains section of the UI before running Certbot.

**`Token not permitted for this endpoint`**

The API token is missing one of the required route permissions. Edit the token in the DashDDI UI and add the three routes listed in the Setup section above.

**Validation fails despite record being created**

Increase the propagation wait time:

```bash
certbot certonly \
  --authenticator dns-dashddi \
  --dns-dashddi-credentials /etc/letsencrypt/dashddi.ini \
  --dns-dashddi-propagation-seconds 60 \
  -d myhost.domain.com
```
