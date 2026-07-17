# proxmox-acme-dashddi

A Proxmox VE ACME DNS challenge plugin that uses the [DashDDI](https://github.com/goshencollege/dashddi) host self-service API to perform DNS-01 challenges. This allows Proxmox to obtain and renew TLS certificates automatically without exposing broad API credentials.

## Requirements

- Proxmox VE 7.x or 8.x
- The Proxmox node must be registered as a host in DashDDI with at least one network interface whose IP address matches the node
- The node's A/AAAA record must exist in DashDDI and be in a domain with at least one **public** DNS view
- A host-scoped token generated from the host detail page (see Setup)

## Files

| File | Purpose |
|---|---|
| `dns_dashddi.sh` | The ACME plugin script |
| `dns-challenge-schema.snippet.json` | JSON fragment to merge into Proxmox's schema file |

## How it works

1. Proxmox's ACME client calls `dns_dashddi_add` with the validation domain name and token value.
2. The plugin strips the `_acme-challenge.` prefix to recover the source FQDN and calls `POST /api/self/dns-challenge` on DashDDI.
3. DashDDI creates the TXT record and assigns it to all views the domain belongs to — including the public view that Let's Encrypt queries.
4. After validation, `dns_dashddi_rm` calls `DELETE /api/self/dns-challenge` to clean up.

## Setup

### 1. Generate a host-scoped token

In the DashDDI UI, navigate to **Hosts**, open the record for this Proxmox node, and click **Generate Token** in the Host API Token card. Copy the token — it is shown only once.

The token is automatically restricted to requests from this host's IP addresses and can only access the `/api/self/*` endpoints.

### 2. Install the plugin script and register the schema entry

Clone the DashDDI repository on the Proxmox node (a shallow clone is sufficient), then run `install.sh` as root. It copies the plugin script, merges the schema snippet (backing up the original first), validates the result, and restarts the necessary services:

```bash
git clone --depth=1 https://github.com/goshencollege/dashddi.git
cd dashddi/proxmox-acme-dashddi
sudo bash install.sh
```

To skip the automatic service restart (e.g. if you want to restart manually during a maintenance window):

```bash
sudo bash install.sh --no-restart
systemctl restart pveproxy pvedaemon
```

The script is idempotent — running it again updates the plugin in place.

<details>
<summary>Manual installation (without the script)</summary>

```bash
# Copy the plugin script
cp dns_dashddi.sh /usr/share/proxmox-acme/dnsapi/
chmod +x /usr/share/proxmox-acme/dnsapi/dns_dashddi.sh
```

Add the `dashddi` entry from `dns-challenge-schema.snippet.json` into the top-level object in `/usr/share/proxmox-acme/dns-challenge-schema.json`. **A JSON syntax error in this file breaks ACME plugin listing entirely until fixed.** Validate after editing:

```bash
python3 -m json.tool /usr/share/proxmox-acme/dns-challenge-schema.json > /dev/null && echo OK
systemctl restart pveproxy pvedaemon
```

</details>

### 5. Configure the plugin in the Proxmox UI

1. Go to **Datacenter → ACME → Challenge Plugins → Add**
2. Set Plugin Type to **DNS**
3. Select **dashddi** from the DNS API dropdown
4. Fill in `DASHDDI_API_URL` and `DASHDDI_API_TOKEN`
5. If your DashDDI instance uses a self-signed or internal CA certificate, set `DASHDDI_CA_CERT` to the path of the PEM CA bundle, or `false` to disable SSL verification (not recommended for production)
6. Save

### 6. Configure ACME on the node

1. Go to **Node → System → Certificates → ACME**
2. Set up an ACME account if you haven't already
3. Add a domain entry using the **dashddi** challenge plugin
4. Click **Order Certificates Now** to issue the first certificate

Proxmox will automatically renew the certificate before expiry.

## Upgrading

Since this is not an officially supported extension point, the plugin script and schema edit are **not preserved** if `libproxmox-acme-plugins` is purged and reinstalled (a normal `apt upgrade` is fine). Keep this repository as the source of truth and redeploy both files as part of any node reprovisioning.

Because ACME plugin *configuration* lives in `/etc/pve/priv/acme/plugins.cfg` (cluster-replicated via pmxcfs), you only need to configure the plugin once in the UI — but you must copy the script file and patch the schema on **each node individually**.

## Troubleshooting

**`dashddi` does not appear in the DNS API dropdown**

The schema edit didn't take effect. Re-validate the JSON, correct any syntax errors, and restart `pveproxy` and `pvedaemon`.

**`401 Unauthorized`**

The token is invalid, or the request is coming from an IP address not assigned to this host in DashDDI. Verify the token and check that the node's primary IP is registered as an interface in DashDDI.

**`403 Forbidden` — "FQDN does not belong to this host"**

The domain being certified does not have an A or AAAA record linked to this node's interfaces in DashDDI. Add the record before ordering a certificate.

**`422 Unprocessable Entity` — "no public views"**

The domain has DNS views configured but none are marked public. In DashDDI, go to **DNS → Views**, edit the internet-facing view, and enable the **Public view** checkbox.

**SSL certificate verification failure**

Set `DASHDDI_CA_CERT` in the plugin configuration to the path of your internal CA bundle, or `false` to skip verification during testing.
