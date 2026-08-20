# Aruba CX Switch Integration

DashDDI can query Aruba CX switches to retrieve port access information and perform port management actions (reauthenticate, bounce, POE bounce). This integration is used in conjunction with ClearPass — every known device's cached switch/port location (`switchIp`/`switchPort` on its `NetworkInterface`) is what DashDDI uses to identify the switch port to act on.

## How It Works

DashDDI contacts Aruba CX switches using the switch's REST API (primary) or SSH (fallback). It can:

- **Retrieve port client info** — which device is connected to a port, its MAC, VLAN, authentication status, and role
- **Reauthenticate a port** — trigger 802.1X reauthentication without disrupting connectivity
- **Bounce a port** — administratively shut down and re-enable a port (forces device reconnection)
- **POE bounce** — power-cycle the PoE output on a port (useful for IP phones and APs)

Port operations are initiated from within DashDDI's interface — on a device's interface page, or from the **Switch Ports** card on a host's detail page. Every action resolves its target switch/port from the device's cached switch attachment (`App\Controller\Api\SwitchApiController::resolveSwitch()`), which is kept current by both ClearPass auth log processing and the live **Query Switch** scan (see [Live Port Scan](#live-port-scan-query-switch) below) — never by a live re-query of ClearPass at action time.

## Configuring an Aruba CX Switch

Add a switch under **Settings → Aruba Switches**. The fields are:

| Field | Description |
|---|---|
| Username | Username for REST API and SSH login |
| Password | Password for REST API authentication and SSH password fallback |
| REST API Version | API version for REST calls (default: `v10.12`) |
| Verify TLS | Whether to validate the switch's TLS certificate (default: **on** — recommended; uncheck only if the switch uses a self-signed certificate) |
| Description | Optional notes about this switch configuration |

**Note:** DashDDI stores one global set of credentials. The actual switch IP is resolved per device at action time from its cached switch attachment (see [How It Works](#how-it-works) above), not stored on this record.

### Authentication Options

DashDDI supports two connection methods:

| Method | Used For | Requirements |
|---|---|---|
| REST API | Port info retrieval (primary) | Username + Password configured |
| SSH | Port info retrieval (fallback), all port actions | Username + Password or SSH Private Key |

If both REST and SSH credentials are available, DashDDI tries REST first for queries and falls back to SSH on failure. Port actions (bounce, reauthenticate, POE bounce) always use SSH.

## Port ID Format

Port IDs in ClearPass auth logs are typically in `NAS-Port-ID` format, for example `1/1/5 - Profile Name`. DashDDI normalizes these automatically, extracting just the port identifier (`1/1/5`) before sending it to the switch.

## Usage Flow

1. DashDDI learns a device's switch/port location either from a ClearPass auth log pull (NAS IP/NAS Port ID) or from a live **Query Switch** scan finding it on a port — either way, it's cached on the device's `NetworkInterface`.
2. On that device's interface page, or the host's **Switch Ports** card, you can view port details: connected device, VLAN, auth method, and role.
3. From the same interface, you can trigger reauthentication, bounce, or POE bounce on that port.

## TLS Certificate Note

TLS verification is enabled by default and recommended. Disable it only if the switch uses a self-signed certificate that cannot be added to the server's trust store.

## Live Port Scan (Query Switch)

The **Switch Ports** card on a host's detail page (shown when that host is itself
acting as a NAS device) has a **Query Switch** button. Clicking it gathers, for
every port:

- Interface link status/speed
- Port-access (802.1X/MAC-Auth) clients
- The MAC address table
- LLDP neighbor info

Each of the four is fetched via the REST API first:

- Link status/speed: `GET /system/interfaces?depth=2`
- Port-access clients and LLDP neighbors: `GET /system/interfaces?depth=1` to list
  interface names, then `GET /system/interfaces/{port}/port_access_clients?depth=2`
  and `GET /system/interfaces/{port}/lldp_neighbors?depth=2` per port. AOS-CX
  doesn't expand either of these reference collections when nested inside a
  `/system/interfaces?depth=N` collection response — `depth=2` leaves them as bare
  URIs, and `depth>=3` on the full collection times out on real hardware — so both
  need their own per-interface call.
- MAC address table: `GET /system/vlans?depth=1` to list VLANs (`depth=0` is
  rejected outright by this firmware — 1 is the minimum), then
  `GET /system/vlans/{vlan}/macs?depth=2` per VLAN.

Any of the four that REST doesn't cover on a given switch/firmware (unconfigured
credentials, a failed request, or a response that parses to zero entries) falls
back to its SSH-parsed CLI equivalent (`show interface brief`,
`show port-access clients`, `show mac-address-table`, `show lldp neighbor-info`,
all run in a single SSH session). If REST supplies all four, no SSH connection is
opened at all. The results are parsed and merged by port
(`App\Service\ArubaCxService::scanSwitch()` and `App\Service\AosCxOutputParser` for
the CLI-fallback parsing), then correlated against DashDDI's cached switch/port
data (`App\Service\SwitchPortCorrelationService`) to produce one row per port
with:

- Live link status and speed
- Live MAC address(es), classified as an **uplink** (many MACs — a trunk to
  another switch) to avoid flagging expected noise there
- Applied policy — the 802.1X/MAC-Auth role(s) RADIUS assigned to the port's
  live client(s), from the port-access client data
- LLDP neighbor name/port, plus the neighbor's own MAC when the switch reports
  one — used by the UI to show the neighbor against whichever of the port's
  live devices it actually belongs to (e.g. a daisy-chained phone) rather than
  the whole port
- **Discrepancies**: `unregistered` (live MAC unknown to DashDDI), `moved` (known
  device live on a different port than cached), `stale` (cached device not seen
  live anywhere)

There is deliberately no VLAN-mismatch check — with overlay networking, a port's
live VLAN commonly differs from its assigned subnet's VLAN without that being a
problem, so the comparison isn't useful here.

This never writes anything to the switch itself, but it does update DashDDI's own
database: for every live MAC that's both **known** (matches an existing
`NetworkInterface`) and on a **non-uplink** port, the scan updates that
interface's cached switch attachment (`switchIp`/`switchPort`/`lastAuthAt` — the
same fields ClearPass auth log processing already maintains) if the scan is newer
than what's cached, and records the observation in the append-only
`SwitchPortLog` history table (source `clearpass` or `live_scan`, retained per the
**Switch Port Log Retention** setting in Application Settings). This is what lets
the action buttons (Check Status, Reauthenticate, Bounce, PoE Bounce) work
immediately on a device the scan just discovered, without waiting on the next
scheduled ClearPass pull — `App\Controller\Api\SwitchApiController::resolveSwitch()`
resolves every action's target switch/port from that same cached attachment.
Uplink ports are excluded because the MACs seen there are trunked-through traffic
from other devices beyond the switch, not genuine attachments to that port.

It requires Aruba CX credentials with a password (for REST) and/or SSH access
(key or password, for CLI fallback) to be configured.

**Note:** the REST paths for port-access clients, the MAC table, and LLDP
neighbors above have been confirmed against a real AOS-CX 10.12 switch. The
CLI-fallback output parsers (`App\Service\AosCxOutputParser`) were written from
general AOS-CX conventions rather than real device output, and may still need
adjustments once exercised against a switch/firmware where REST is unavailable.
