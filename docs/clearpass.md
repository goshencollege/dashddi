# ClearPass Integration

DashDDI integrates with Aruba ClearPass Policy Manager in two directions:

- **Push** — DashDDI sends host and interface data to ClearPass as managed endpoints, keeping the ClearPass device inventory up to date.
- **Pull** — DashDDI retrieves authentication session records from ClearPass Insight, giving you a searchable history of which devices authenticated where and when.

## Configuring ClearPass

Add a ClearPass connection under **Settings → ClearPass Servers**. The fields are:

| Field | Description |
|---|---|
| Name | Display name for this connection |
| API URL | Base URL of the ClearPass REST API (e.g., `https://clearpass.example.com`) |
| Client ID | OAuth application client ID (see below) |
| Client Secret | OAuth application client secret |
| Verify TLS | Whether to validate ClearPass's TLS certificate (default: on) |

### OAuth Application Setup

DashDDI authenticates to ClearPass using OAuth client credentials. To create the application:

1. In ClearPass: **Administration → API Services → API Clients**
2. Create a new API client with:
   - **Grant Type:** `client_credentials`
   - **Operating Mode:** `Rest API Client`
   - **Operator Profile:** A profile with at minimum:
     - Read/write access to **Endpoint** (for push)
     - Read access to **Session** / Insight API (for pull)
3. Copy the **Client ID** and **Client Secret** into DashDDI.

DashDDI obtains a fresh access token before each API call — no token management is needed on your end.

---

## Push — Endpoint Management

DashDDI pushes network interface data to the ClearPass Endpoint repository. This allows ClearPass policies to reference DashDDI-managed attributes (hostname, subnet, VLAN, tags) without manual data entry.

### What Gets Pushed

For each interface that has the RADIUS authentication tag set, DashDDI creates or updates a ClearPass endpoint with the following attributes:

| ClearPass Attribute | Source |
|---|---|
| `mac_address` | Interface MAC address |
| `status` | Always `Known` |
| `Managed By` | Always `DashDDI` (used internally to track managed endpoints) |
| `Device Name` | Host name |
| `Hostname` | Primary DNS name of the interface |
| `IP Address` | IPv4 address |
| `IPv6 Address` | IPv6 address |
| `Subnet` | IPv4 CIDR of the subnet |
| `VLAN ID` | VLAN number from the subnet |
| `Tags` | Pipe-delimited list of host tags |

### Push Behavior

- **Create:** If the MAC doesn't exist in ClearPass, a new endpoint is created.
- **Update:** If the MAC already exists, the endpoint attributes are updated.
- **Delete:** If an interface is soft-deleted from DashDDI, the corresponding ClearPass endpoint is removed — but only if its `Managed By` attribute is `DashDDI`. Endpoints created outside DashDDI are never deleted.

### Console Command

```bash
docker compose exec app bin/console app:push-clearpass [--mac=<mac_address>]
```

| Option | Description |
|---|---|
| *(no options)* | Push all RADIUS-tagged interfaces |
| `--mac=<mac>` | Push a single interface by MAC address (useful for testing) |

### Scheduled Push

ClearPass pushes can be scheduled under **Settings → Scheduled Tasks**. Two task types are available:

- **ClearPass Push (single server)** — fast push via the priority queue
- **ClearPass Full Sync** — full deployment via the bulk queue

---

## Pull — Authentication Logs

DashDDI retrieves RADIUS authentication session records from the ClearPass Insight API and stores them locally. This gives you a searchable log of device authentication history directly within DashDDI, linked to your host and interface records.

### What Gets Pulled

For each session record, DashDDI stores:

| Field | Description |
|---|---|
| Session ID | Unique ClearPass session identifier |
| MAC Address | Client MAC address |
| Auth Timestamp | When the authentication occurred |
| IP Address | IP address assigned during the session |
| Username | 802.1X username (if applicable) |
| Service | ClearPass service matched |
| Auth Protocol | Authentication protocol used (e.g., EAP-TLS, PEAP) |
| NAS IP | IP address of the authenticating switch/AP |
| NAS Port ID | Port identifier on the switch/AP (e.g., `1/1/5`) |
| Role | Enforcement role applied by ClearPass |
| VLAN | VLAN assigned by ClearPass |

Each record is linked to the matching DashDDI NetworkInterface (matched by MAC address), enabling you to navigate from an interface to its full authentication history.

### Pull Behavior

- On the **first run**, DashDDI fetches sessions from the past hour.
- On **subsequent runs**, only sessions newer than the last pull are fetched.
- Duplicate session IDs are detected and skipped.
- Sessions are fetched in batches of 1000 and ordered by timestamp.

### Console Command

```bash
docker compose exec app bin/console app:pull-clearpass-logs
```

### Scheduled Pull

Auth log pulls can be scheduled under **Settings → Scheduled Tasks**.

---

## Aruba CX Switch Integration

When viewing a ClearPass auth log entry, DashDDI can use the **NAS IP** and **NAS Port ID** to query the Aruba CX switch directly and show real-time port status. See [Aruba CX Switch](aruba-cx-switch.md) for configuration details.

From an auth log entry you can:

- View port client info (current device, VLAN, auth status)
- Trigger port reauthentication
- Bounce the port (admin down/up)
- POE bounce the port
