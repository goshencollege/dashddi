# DNS / BIND Integration

DashDDI generates BIND zone files from your domain and subnet data and deploys them to one or more BIND servers via SSH.

## How It Works

1. DashDDI reads all domains, subnets, DNS views, and network interfaces from the database.
2. It generates BIND-compatible zone files (forward zones per domain, reverse zones per subnet) and a `dashddi.conf` file that defines ACLs and DNSSEC policies.
3. The generated files are deployed to each DNS server via SFTP.
4. After uploading, DashDDI runs `rndc reload` on the remote server to apply the changes without restarting BIND.

Deployments are triggered automatically via the async message queue whenever DNS-relevant data changes, or manually via the console command.

## Configuring a DNS Server

Add a DNS server under **Settings → DNS Servers**. The fields are:

| Field | Description |
|---|---|
| Name | Display name for this server |
| Hostname | IP address or hostname DashDDI connects to via SSH |
| SSH User | User account for the SSH connection (default: `root`) |
| SSH Private Key | Private key used to authenticate. Generate one in DashDDI and copy the public key to the server's `authorized_keys`. |
| Remote Zone Path | Directory on the server where zone files are written (default: `/etc/bind/zones`) |
| Key Directory | Path to the DNSSEC key directory on the remote server (optional) |
| Server Type | `primary` or `secondary` |
| Primary Hostname | For secondary servers: the IP/hostname of the primary BIND server (used in `primaries` directives) |
| Views | Which DNS views this server serves |

### SSH Key Setup

DashDDI generates an Ed25519 SSH key pair per server. To authorize DashDDI:

1. Open the DNS server record in DashDDI and copy the public key.
2. Add it to `~/.ssh/authorized_keys` (or the configured user's home) on the BIND server.
3. Ensure the SSH user has write access to the remote zone path and can run `rndc`.

## DNS Views

DashDDI uses views to serve different zone data to different clients. Each DNS server can be assigned one or more views. Each domain and subnet belongs to one or more views; only records in matching views are included in the zone files uploaded to a given server.

Secondary servers can only be assigned a single view.

## Zone File Structure

Files are written to `{remoteZonePath}/{viewName}/`:

- **Forward zones:** one file per domain (e.g., `example.com.zone`)
- **Reverse zones:** one file per subnet (e.g., `10.0.1.0.zone` for IPv4, `ip6.arpa` zone for IPv6)
- **`dashddi.conf`:** ACL definitions and all `view {}` blocks with `zone {}` stanzas

Includes:
- SOA record (serial, refresh, retry, expire, TTL from domain/subnet settings)
- A/AAAA records from interface names
- PTR records where forward-confirmed reverse DNS is valid
- DNSSEC inline-signing directives when a DNSSEC policy is configured
- RFC 2317 classless delegation for IPv4 reverse zones

## DNSSEC

To enable DNSSEC for a domain, assign a DNSSEC policy to it. DashDDI will emit `dnssec-policy` directives in the zone file. Key management (KSK/ZSK generation and rollover) is handled by BIND; DashDDI references the key directory you specify on the server.

## Console Command

```bash
docker compose exec app bin/console app:dns:generate-config [--output-dir=/tmp/dns-zones] [--deploy]
```

| Option | Description |
|---|---|
| `--output-dir` | Write generated zone files to a local directory for inspection |
| `--deploy` | Deploy to all configured DNS servers via SSH |

Run without `--deploy` to preview what would be generated.

## Scheduled Deployment

DNS pushes can be scheduled under **Settings → Scheduled Tasks**. The task runs `app:dns:generate-config --deploy` via the bulk message queue on the configured cron schedule.

## Push Logs

Every deployment is recorded in **Push Logs** (accessible from the navigation). Each entry shows which server was targeted, whether the push succeeded, and the full response detail for troubleshooting failures.
