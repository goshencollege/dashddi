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
| Name | Display name for this switch |
| Hostname / IP | Address of the switch (entered when performing an action, not stored on the switch record) |
| Username | Username for REST API and SSH login |
| Password | Password for REST API login and SSH password authentication |
| SSH Private Key | SSH private key for key-based SSH authentication (alternative to password for SSH) |
| REST API Version | API version for REST calls (default: `v10.12`) |
| Verify TLS | Whether to validate the switch's TLS certificate (default: off — most switches use self-signed certs) |

**Note:** DashDDI stores a set of global credentials. The actual switch IP is provided at action time (from the NAS IP recorded in the ClearPass auth log).

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

Most Aruba CX switches use self-signed TLS certificates. Leave **Verify TLS** disabled unless you have installed a trusted certificate on the switch.
