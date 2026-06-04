# DHCP / Kea Integration

DashDDI generates Kea DHCP configuration from your subnet and host data, deploys it to Kea servers via SSH, and optionally receives lease notifications back from Kea via a webhook endpoint.

## How It Works

1. DashDDI reads subnets, address blocks, and network interfaces from the database.
2. It generates `subnets4.json` (DHCPv4) and `subnets6.json` (DHCPv6) in Kea's JSON format.
3. The files are uploaded to the Kea server via SFTP.
4. Optionally, DashDDI instructs the Kea Control Agent to reload the config. If the reload fails, the previous config is automatically restored.
5. Kea can be configured to POST lease events back to DashDDI's lease endpoint so the DHCP Leases view stays up to date.

Deployments are triggered automatically via the async message queue whenever DHCP-relevant data changes, or manually via the console command.

## Configuring a DHCP Server

Add a DHCP server under **Settings → DHCP Servers**. The fields are:

| Field | Description |
|---|---|
| Name | Display name for this server |
| Hostname | IP address or hostname DashDDI connects to via SSH |
| SSH User | User account for the SSH connection (default: `root`) |
| SSH Private Key | Private key used to authenticate. Generate one in DashDDI and copy the public key to the server's `authorized_keys`. |
| Remote Path | Directory on the server where config files are written (default: `/etc/kea`) |
| Control URL | HTTP(S) URL of the Kea Control Agent (e.g., `http://localhost:8000`). Required for config reload. |
| Control User | Basic auth username for the Control Agent (optional) |
| Control Password | Basic auth password for the Control Agent (optional) |
| DDNS Enabled | When checked, DashDDI also generates and deploys `kea-dhcp-ddns.conf` for the Kea D2 daemon (see Dynamic DNS below). |

### SSH Key Setup

DashDDI generates an Ed25519 SSH key pair per server. To authorize DashDDI:

1. Open the DHCP server record in DashDDI and copy the public key.
2. Add it to the SSH user's `authorized_keys` on the Kea server.
3. Ensure the SSH user has write access to the remote path.

## Generated Configuration

### `subnets4.json` (DHCPv4)

For each subnet with an IPv4 CIDR, DashDDI emits a subnet block containing:

- **Pools:** all address blocks on the subnet marked as Dynamic (IPv4)
- **Router option:** the subnet's gateway IP (if set)
- **Reservations:** all non-deleted interfaces with a MAC address, mapping `hw-address` → `ip-address` (and optionally `hostname`)

### `subnets6.json` (DHCPv6)

For each subnet with an IPv6 CIDR, DashDDI emits a subnet block containing:

- **Pools:** all address blocks marked as Dynamic (IPv6)
- **Reservations:** all non-deleted interfaces with an IPv6 address and valid MAC, mapping `hw-address` → `ip-addresses` array

Interfaces with the all-zeros MAC (`00:00:00:00:00:00`) are excluded from reservations.

## Kea Control Agent

When a Control URL is configured, DashDDI will:

1. Upload the new config files.
2. POST `{"command": "config-reload", "service": ["dhcp4"]}` (or `dhcp6`) to the Control Agent.
3. If the reload fails:
   - Restore the previous config from the backup taken before upload.
   - Attempt another reload to return Kea to its previous working state.

The result (success/failure, whether a restore occurred) is recorded in the Push Log.

## Dynamic DNS (DDNS)

DashDDI can generate a `kea-dhcp-ddns.conf` file for Kea's D2 (DHCP-DDNS) daemon, which causes Kea to dynamically update BIND forward and reverse zones as leases are granted.

### How it works

1. You configure a **TSIG key** on a DNS server (algorithm + secret).
2. You enable **DDNS** on a domain and point it at that DNS server.
3. You assign a **DDNS Domain** to each subnet whose reverse zone should also receive dynamic updates.
4. When DashDDI pushes DHCP config with **DDNS Enabled** checked on the DHCP server, it also generates and uploads `kea-dhcp-ddns.conf` alongside the regular subnet files.
5. The Kea D2 daemon picks up the config and sends authenticated DNS UPDATE packets to BIND.

### Enabling DDNS on a DHCP Server

Check **Dynamic DNS (DDNS)** on the DHCP server record. When this is enabled, DashDDI:

- Generates `kea-dhcp-ddns.conf` containing forward and reverse domain entries for every domain / subnet with DDNS configured.
- Deploys the file to the same remote path as the subnet config.
- Signals the D2 daemon to reload via the Control Agent.

### Prerequisites on the Kea server

1. Install the `kea-dhcp-ddns-server` package (it ships separately from `kea-dhcp4-server`).
2. Enable the `run_script` or `ddns` hook in `kea-dhcp4.conf` so Kea sends DNS updates to D2.
3. Expose D2 to the Control Agent — add a `d2` service entry in `kea-ctrl-agent.conf`:

```json
"control-sockets": {
    "d2": {
        "socket-type": "unix",
        "socket-name": "/var/run/kea/kea-ddns-ctrl-socket"
    }
}
```

4. Ensure `/var/run/kea/` is writable by the kea user.

### Generated `kea-dhcp-ddns.conf`

DashDDI generates a D2 config that includes:

- **TSIG key blocks** — one per DNS server that has DDNS configured (algorithm + secret)
- **Forward DDNS domains** — one entry per domain with DDNS enabled, pointing at the DNS server IP
- **Reverse DDNS domains** — one entry per subnet whose DDNS domain resolves to a configured DNS server

The D2 daemon listens on `127.0.0.1:53001` and communicates with the Kea DHCPv4/v6 server via a named socket at `/var/run/kea/kea-ddns-ctrl-socket`.

> **Note:** D2 requires a literal IP address for DNS servers — not a hostname. DashDDI resolves the DNS server's hostname automatically using the system resolver when generating the config.

## Console Command

```bash
docker compose exec app bin/console app:dhcp:generate-config [--output-dir=/tmp/dhcp] [--reload]
```

| Option | Description |
|---|---|
| `--output-dir` | Write generated JSON files to a local directory for inspection |
| `--reload` | After deploying, signal Kea to reload the config via the Control Agent |

## Scheduled Deployment

DHCP pushes can be scheduled under **Settings → Scheduled Tasks**. The task runs `app:dhcp:generate-config --reload` via the bulk message queue on the configured cron schedule.

## Push Logs

Every deployment is recorded in **Push Logs**. Each entry shows which server was targeted, success/failure, and the full Control Agent response for troubleshooting.

---

## Receiving DHCP Leases (Webhook)

DashDDI provides an API endpoint that Kea can call whenever a lease is granted or renewed. Received leases appear in the **DHCP Leases** view and are automatically associated with the matching subnet.

### Endpoint

```
POST /api/dhcp/lease
```

Authentication uses an API token. Create one under **Settings → API Tokens** with permission for this route.

### Request Body

```json
{
    "ip-address": "192.0.2.100",
    "hw-address": "aa:bb:cc:dd:ee:ff",
    "hostname": "mydevice.example.com",
    "expire": 1748473200
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `ip-address` | string | Yes | The leased IPv4 or IPv6 address |
| `hw-address` | string | Yes | The client's MAC address |
| `hostname` | string | No | Hostname supplied by the DHCP client |
| `expire` | integer | No | Unix timestamp when the lease expires |

### Response

```json
{"id": 42}
```

HTTP `201 Created` on success. HTTP `400 Bad Request` if `ip-address` or `hw-address` is missing or the body is not valid JSON.

### Configuring Kea to Send Leases

Use the Kea **`run_script`** hook library (Kea 2.2+) or the **`lease_cmds`** + a custom script to POST lease events to DashDDI. A minimal example using `run_script`:

> **Note:** Some versions of the `run_script` hook enforce that scripts must reside under `/usr/share/kea/scripts/`. If Kea fails to start with `RUN_SCRIPT_LOAD_ERROR invalid path specified`, place your script there instead of `/etc/kea/`.

**`/usr/share/kea/scripts/notify-dashddi.sh`:**
```bash
#!/bin/bash
# Called by Kea run_script hook on lease4_select and lease4_renew events
if [[ "$LEASES4_AT0_ADDRESS" == "" ]]; then exit 0; fi

curl -s -X POST https://dashddi.example.com/api/dhcp/lease \
  -H "Authorization: Bearer <YOUR_API_TOKEN>" \
  -H "Content-Type: application/json" \
  -d "{
    \"ip-address\": \"$LEASES4_AT0_ADDRESS\",
    \"hw-address\": \"$LEASES4_AT0_HWADDR\",
    \"hostname\": \"$LEASES4_AT0_HOSTNAME\",
    \"expire\": $LEASES4_AT0_CLTT
  }"
```

In `kea-dhcp4.conf`:
```json
"hooks-libraries": [
    {
        "library": "/usr/lib/kea/hooks/libdhcp_run_script.so",
        "parameters": {
            "name": "/usr/share/kea/scripts/notify-dashddi.sh",
            "sync": false
        }
    }
]
```

The same pattern applies for DHCPv6 using the `LEASES6_AT0_*` environment variables.

### Lease Data in DashDDI

The **DHCP Leases** page (`/dhcp/leases`) lets you search leases by MAC address, IP address, or subnet. Each lease record shows:

- MAC address (linked to the matching interface if found)
- IP address
- Hostname (from the DHCP client)
- Subnet (auto-detected from the IP)
- Lease start time and expiry
