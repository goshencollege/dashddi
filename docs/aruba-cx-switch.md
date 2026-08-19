# Aruba CX Switch Integration

DashDDI can query Aruba CX switches to retrieve port access information and perform port management actions (reauthenticate, bounce, POE bounce). This integration is used in conjunction with the ClearPass authentication log view — when a device's auth log entry is selected, DashDDI can look up which port it is connected to and take action on that port.

## How It Works

DashDDI contacts Aruba CX switches using the switch's REST API (primary) or SSH (fallback). It can:

- **Retrieve port client info** — which device is connected to a port, its MAC, VLAN, authentication status, and role
- **Reauthenticate a port** — trigger 802.1X reauthentication without disrupting connectivity
- **Bounce a port** — administratively shut down and re-enable a port (forces device reconnection)
- **POE bounce** — power-cycle the PoE output on a port (useful for IP phones and APs)

Port operations are initiated from within DashDDI's interface when viewing ClearPass authentication logs — the `nasPortId` from the auth log is used to identify the switch port.

## Configuring an Aruba CX Switch

Add a switch under **Settings → Aruba Switches**. The fields are:

| Field | Description |
|---|---|
| Username | Username for REST API and SSH login |
| Password | Password for REST API authentication and SSH password fallback |
| REST API Version | API version for REST calls (default: `v10.12`) |
| Verify TLS | Whether to validate the switch's TLS certificate (default: **on** — recommended; uncheck only if the switch uses a self-signed certificate) |
| Description | Optional notes about this switch configuration |

**Note:** DashDDI stores one global set of credentials. The actual switch IP is provided at action time (from the NAS IP recorded in the ClearPass auth log) and is not stored in this record.

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

1. A device authenticates through ClearPass. DashDDI pulls the auth log, which includes the NAS IP (switch IP) and NAS Port ID (switch port).
2. From the auth log entry in DashDDI, you can view port details: connected device, VLAN, auth method, and role.
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

Each of the four is fetched via the REST API first — `GET /system/interfaces?depth=2`
for link status/speed, `GET /system/interfaces?depth=4` (to expand each interface's
`port_access_clients`/`lldp_neighbors` reference collections into full objects) for
port-access clients and LLDP, and `GET /system/vlans/{vlan}/macs?depth=2` per VLAN
for the MAC table. Any of the four that REST doesn't cover on a given switch/firmware
(unconfigured credentials, a failed request, or a response that parses to zero
entries) falls back to its SSH-parsed CLI equivalent (`show interface brief`,
`show port-access clients`, `show mac-address-table`, `show lldp neighbor-info`,
all run in a single SSH session). If REST supplies all four, no SSH connection is
opened at all. The results are parsed and merged by port
(`App\Service\ArubaCxService::scanSwitch()` and `App\Service\AosCxOutputParser` for
the CLI-fallback parsing), then correlated against DashDDI's cached
ClearPass-derived switch/port data (`App\Service\SwitchPortCorrelationService`) to
produce one row per port with:

- Live link status and speed
- Live MAC address(es), classified as an **uplink** (many MACs — a trunk to
  another switch) to avoid flagging expected noise there
- LLDP neighbor name/port
- **Discrepancies**: `unregistered` (live MAC unknown to DashDDI), `moved` (known
  device live on a different port than cached), `stale` (cached device not seen
  live anywhere)

There is deliberately no VLAN-mismatch check — with overlay networking, a port's
live VLAN commonly differs from its assigned subnet's VLAN without that being a
problem, so the comparison isn't useful here.

This is entirely read-only — nothing is written back to DashDDI's database or to
the switch. It requires Aruba CX credentials with a password (for REST) and/or SSH
access (key or password, for CLI fallback) to be configured.

**Note:** both the REST JSON parsing and the CLI output parsers were written from
general AOS-CX conventions, not from a real device, and may need small adjustments
once run against a specific switch's firmware/output format.
