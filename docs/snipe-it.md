# Snipe-IT Integration

DashDDI can pull asset data from [Snipe-IT](https://snipeitapp.com/) and automatically create or update host and interface records. This keeps your IPAM data in sync with your asset inventory without manual data entry.

## How It Works

1. DashDDI fetches all active (non-archived) assets from the Snipe-IT API.
2. For each asset, it reads MAC addresses from one or more configured custom fields.
3. It creates or updates a DashDDI **Host** and **Network Interface** for each MAC found.
4. Assets that are later archived or deleted in Snipe-IT cause the corresponding DashDDI host to be soft-deleted or unlinked.

## Configuring Snipe-IT

Add a Snipe-IT connection under **Settings → Snipe-IT Servers**. The fields are:

| Field | Description |
|---|---|
| Name | Display name for this connection |
| API URL | Base URL of the Snipe-IT API (e.g., `https://snipeit.example.com/api/v1`) |
| API Key | Snipe-IT API token. Generate one in Snipe-IT under your user profile → API. |
| MAC Custom Fields | Comma-separated Snipe-IT custom field names that contain MAC addresses. Optionally append `:alias` to each name to control the interface name in DashDDI (e.g., `MAC Address, WiFi MAC Address:wifi, Management MAC:mgmt`). |
| Verify TLS | Whether to validate Snipe-IT's TLS certificate (default: on) |

### API Token

In Snipe-IT: **Profile → API Keys → Create**. The token is used as a Bearer token for all API requests. Grant read-only access if you only want DashDDI to pull — no write access to Snipe-IT is required.

### MAC Custom Fields

Snipe-IT does not have a built-in MAC address field. You must create one (or more) custom fields in Snipe-IT and add them to your asset fieldsets. Enter the exact field names (as they appear in Snipe-IT) in the **MAC Custom Fields** setting, comma-separated.

DashDDI handles multiple MAC addresses in a single field — values can be separated by newlines, semicolons, or commas within the field value.

#### Interface Names

Each imported network interface is named after the Snipe-IT field it came from. You can control this name by appending `:alias` to the field entry:

```
MAC Address, WiFi MAC Address:wifi, Management MAC:mgmt
```

Without an explicit alias, DashDDI derives a short name automatically by stripping common noise words ("mac address", "mac", "address") from the end of the field name and slugifying the result — for example, "WiFi MAC Address" becomes `wifi` and "Primary MAC" becomes `primary`.

Interfaces that already have a name (set manually or by a previous sync) are never overwritten. Interfaces with no name are backfilled on the next sync.

## Category → Subnet Mapping

You can configure mappings between Snipe-IT asset categories and DashDDI subnets. When a new interface is created from a Snipe-IT asset, DashDDI automatically assigns it to the subnet configured for that asset's category.

Configure mappings on the Snipe-IT server record under **Category Subnet Maps**.

## Sync Behavior

### When an Asset Has a MAC That DashDDI Doesn't Know About
A new Host and NetworkInterface are created. The host is tagged with `snipeit` to indicate it originated from a sync.

### When an Asset Matches an Existing Unlinked Host
DashDDI **adopts** the existing host rather than creating a duplicate. The host is linked to the Snipe-IT asset and marked as adopted. The host name is preserved as-is.

### When Multiple Hosts Share the Asset's MACs
If several existing unlinked hosts each match one of the asset's MACs, DashDDI merges them into one host and links it to the asset.

### On Subsequent Syncs
- Host names are updated to match the Snipe-IT asset name (unless the name was manually changed after the last sync).
- Interfaces whose MACs no longer appear in the asset are soft-deleted.
- Interfaces whose MACs reappear (e.g., re-added in Snipe-IT) are restored.
- Interfaces with no name are backfilled with the alias derived from the source field.

### When an Asset Is Archived or Deleted in Snipe-IT
- **Adopted hosts** (pre-existing before the sync): the `snipeit` tag is removed and the link is deleted. The host is preserved in DashDDI.
- **Sync-created hosts**: the host and its interfaces are soft-deleted.

## Console Command

```bash
docker compose exec app bin/console app:pull-snipe-it [--server-id=<id>]
```

| Option | Description |
|---|---|
| `--server-id` | Sync only the specified Snipe-IT server. Omit to sync all configured servers. |

The command prints a summary:

```
Created:  5
Updated: 10
Deleted:  2
Skipped:  3
```

- **Created** — new hosts added to DashDDI
- **Updated** — existing hosts refreshed
- **Deleted** — hosts soft-deleted because the asset was removed from Snipe-IT
- **Skipped** — assets with no valid MAC addresses in the configured custom fields

## Scheduled Sync

Snipe-IT pulls can be scheduled under **Settings → Scheduled Tasks**. The task runs via the bulk message queue on the configured cron schedule.

## Notes

- DashDDI does not write anything back to Snipe-IT — the integration is read-only.
- Assets with no value in any configured MAC custom field are silently skipped.
- If TLS verification fails (e.g., self-signed cert), disable **Verify TLS** on the server record.
