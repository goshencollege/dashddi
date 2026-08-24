# dashddi-client-windows

Integrates [win-acme](https://www.win-acme.com/) with
[DashDDI](https://github.com/goshencollege/dashddi) to issue and automatically renew
Let's Encrypt certificates on Windows hosts, and can publish CAA/HTTPS records after
issuance. Certificates are stored in the **Windows Certificate Store**
(`LocalMachine\My`), making them natively available to IIS, WCF, and other Windows
services.

DNS-01 challenge records are created and removed via the DashDDI host self-service API
using a host-scoped token, so no broad DNS credentials are required.

## Requirements

- Windows Server 2016 / Windows 10 or later
- PowerShell 5.1 or later (built into all supported Windows versions)
- The host must be registered in DashDDI with at least one network interface whose IP
  address matches the machine running this script
- The host's A, AAAA, or CNAME record must exist in DashDDI in a domain with at least
  one **public** DNS view (or a public parent domain)
- A host-scoped token generated from the host detail page in DashDDI

## Setup

### 1. Generate a host-scoped token

In the DashDDI UI, navigate to **Hosts**, open the host record for this machine, and
click **Generate Token** in the Host API Token card. Copy the displayed token — it is
shown only once.

### 2. Run the installer

From an **elevated** PowerShell prompt:

```powershell
# Download and inspect first (recommended):
Invoke-WebRequest -Uri https://raw.githubusercontent.com/goshencollege/dashddi/main/dashddi-client-windows/Install-Dashddi.ps1 -OutFile Install-Dashddi.ps1
.\Install-Dashddi.ps1
```

The installer prompts for your DashDDI URL, host-scoped token, and an email address for
ACME account notifications. Pass them directly for unattended use:

```powershell
.\Install-Dashddi.ps1 `
    -Url https://dashddi.example.com `
    -Token your-host-scoped-token `
    -Email admin@example.com
```

### What gets installed

| Path / Item | Purpose |
|---|---|
| `C:\dashddi\wacs.exe` | win-acme ACME client |
| `C:\dashddi\settings.json` | win-acme configuration: uses public DNS (8.8.8.8, 1.1.1.1) for pre-validation |
| `C:\dashddi\Update-DashddiCertificate.ps1` | Daily renewal wrapper: re-queries DashDDI then calls wacs.exe, then publishes CAA/HTTPS records |
| `C:\dashddi\Get-DashddiHosts.ps1` | Called by the renewal wrapper to discover current FQDNs from DashDDI |
| `C:\dashddi\New-DashddiChallenge.ps1` | Hook called by win-acme to create TXT records via DashDDI |
| `C:\dashddi\Remove-DashddiChallenge.ps1` | Hook called by win-acme to remove TXT records after validation |
| `C:\dashddi\Publish-DashddiRecords.ps1` | Called after issuance to create/update CAA/HTTPS records, if configured |
| `C:\dashddi\dashddi.ini` | Credentials file (ACL restricted to SYSTEM + Administrators) |
| Windows Certificate Store → `LocalMachine\My` | Issued certificate |
| Task Scheduler → `Dashddi renewal (SYSTEM)` | Daily task running `Update-DashddiCertificate.ps1` as SYSTEM |

## How it works

The installer registers `Update-DashddiCertificate.ps1` as the `Dashddi renewal
(SYSTEM)` Scheduled Task. On every daily run (and on the initial install) the wrapper
re-queries DashDDI for the current FQDN list before calling wacs.exe, so the
certificate's SAN list stays in sync as DNS records are added or removed — matching the
behaviour of the `dashddi` CLI on Linux.

1. `Update-DashddiCertificate.ps1` calls `Get-DashddiHosts.ps1`, which queries
   `GET /api/self/host` and returns the current A/AAAA/CNAME FQDNs registered to this
   host in DashDDI (or the explicit list from `dns_dashddi_names` if set — see below).
2. The wrapper calls wacs.exe with the current FQDN list. win-acme renews the
   certificate if it is within its renewal window; otherwise it exits cleanly.
3. For each domain that needs validation, Let's Encrypt asks win-acme to prove control
   via a DNS-01 challenge. win-acme calls `New-DashddiChallenge.ps1`, which posts to
   `POST /api/self/dns-challenge` with the FQDN and validation token.
4. DashDDI looks up the matching DNS record on the host — an exact match, or a wildcard
   record covering the requested name — and creates the `_acme-challenge.*` TXT record
   in the appropriate public DNS view (or a multipart label in a public parent domain if
   the host's domain is managed externally, e.g. Active Directory).
5. After validation, win-acme calls `Remove-DashddiChallenge.ps1`, which posts to
   `DELETE /api/self/dns-challenge`.
6. The issued certificate is installed into the Windows Certificate Store under
   `LocalMachine\My`.
7. If `dns_dashddi_caa` and/or `dns_dashddi_https_value` are configured,
   `Publish-DashddiRecords.ps1` creates or updates the corresponding record at each
   issued FQDN via `PUT /api/self/records`.

## Requesting a subset of names, or names covered by a wildcard

By default the renewal wrapper certifies every publicly-reachable FQDN the host owns.
To certify an explicit list instead — a subset of those names, or concrete names not
present as their own record but covered by a wildcard record (e.g. host owns
`*.example.com` and you also want `foo.example.com` and `bar.example.com` as explicit
SAN entries) — set `dns_dashddi_names` in `dashddi.ini`, or pass `-Names` to the
installer:

```powershell
.\Install-Dashddi.ps1 -Url https://dashddi.example.com -Token ... -Email ... `
    -Names foo.example.com,bar.example.com
```

```ini
dns_dashddi_names = foo.example.com,bar.example.com
```

When set, `Get-DashddiHosts.ps1` returns exactly this list instead of querying DashDDI.
DashDDI still enforces ownership per name at DNS-01 validation time — a name matched
only by a wildcard record is accepted; an unrelated name is rejected with a `403`.

## Publishing CAA/HTTPS records after issuance

**CAA** — set `dns_dashddi_caa = true` in `dashddi.ini`, or pass `-Caa` to the installer,
to have every renewal create or update a CAA record at each issued FQDN authorizing
Let's Encrypt (the only ACME server this installer supports, so there's nothing else to
configure):

```powershell
.\Install-Dashddi.ps1 -Url https://dashddi.example.com -Token ... -Email ... -Caa
```

```ini
dns_dashddi_caa = true
```

**HTTPS** — content isn't derivable from the issuance the way the CA is, but you can
still just turn it on and get a generally-safe default (`1 . alpn=h2`, advertising
HTTP/2 — the default deliberately omits `h3`/HTTP/3, since that requires explicit QUIC
support most servers don't have out of the box; if yours does, add it via
`-HttpsValue` below) via `-Https`:

```powershell
.\Install-Dashddi.ps1 -Url https://dashddi.example.com -Token ... -Email ... -Https
```

```ini
dns_dashddi_https = true
```

Or provide an explicit value instead (implies enabling it) via `-HttpsValue`:

```ini
dns_dashddi_https_value = 1 . alpn=h2,h3
```

Either one calls `PUT /api/self/records`, an idempotent upsert matched by
hostname+domain+type, so running it on every renewal is safe. A failure publishing one
of these records is reported as a warning and does not fail the renewal.

**Limitation:** this manages at most one CAA record and one HTTPS record per FQDN. If
you need more than one CAA policy line at a name (e.g. both `issue` and `issuewild`),
manage the extra ones directly in the DashDDI DNS UI.

## Operations

**Force an immediate renewal:**

```powershell
& "C:\dashddi\wacs.exe" --renew --force
```

**List managed renewals:**

```powershell
& "C:\dashddi\wacs.exe" --list
```

**Adding or removing DNS records in DashDDI:**

No action required. `Get-DashddiHosts.ps1` re-queries DashDDI on every renewal, so the
certificate's SAN list updates automatically on the next daily renewal run (unless
`dns_dashddi_names` is set, in which case update that list). To pick up a change
immediately, force a renewal:

```powershell
& "C:\dashddi\wacs.exe" --renew --force
```

## Troubleshooting

**Preliminary validation failed — "No TXT records found" on internal nameservers**

This happens when the domain is hosted on internal/AD DNS servers that are not
reachable from the internet. DashDDI publishes the challenge record in the public
parent zone instead, but win-acme's pre-validation step queries the authoritative
nameservers it finds via local DNS and cannot see the record there.

The installer writes `settings.json` configuring win-acme to use public DNS resolvers
(8.8.8.8, 1.1.1.1) for its pre-validation check, which resolves via the public DNS
hierarchy and finds the record correctly. If this file is missing or was not present
when win-acme was first run, create it manually:

```json
{
  "Validation": {
    "DnsServers": ["8.8.8.8", "1.1.1.1"]
  }
}
```

Save it as `C:\dashddi\settings.json` and retry.

**`401 Unauthorized`**

The token is invalid or the request is coming from an IP not assigned to the host in
DashDDI. Verify the token and that the host's interfaces include the machine's current
IP.

**`403 Forbidden` — "FQDN does not belong to this host"**

The FQDN has no DNS record linked to this host's interfaces in DashDDI (directly, or via
a wildcard record covering it). Add an A or AAAA record before running the installer.

**`422 Unprocessable Entity` — "no public views"**

The domain has no public view and no public ancestor domain in DashDDI. Mark a view as
Public, or ensure a public parent domain exists.

**No FQDNs discovered**

The installer found no A/AAAA/CNAME records reachable from the internet for this host.
Check that the host has DNS records in DashDDI and that the domain (or a parent domain)
has a public view, or set `dns_dashddi_names` to list them explicitly.

## Upgrading from win-acme-dashddi

This project was renamed from `win-acme-dashddi` to `dashddi-client-windows`, and its
scripts renamed to a consistent `Dashddi`-prefixed naming (also fixing non-approved
PowerShell verbs along the way: `Create-`/`Delete-`/`Renew-` → `New-`/`Remove-`/
`Update-`). The default install path also changed from `C:\win-acme` to `C:\dashddi`.

**Nothing on the DashDDI server side changes** — this is a purely local rename, and
`GET /api/self/host` and `POST`/`DELETE /api/self/dns-challenge` behave exactly as
before, so an existing installation keeps renewing without any action.

To move an existing install to the new naming:

1. Re-run the installer with the new script, pointing at your existing install path
   (or move `C:\win-acme` to `C:\dashddi` first and use the default):
   ```powershell
   .\Install-Dashddi.ps1 -Url https://dashddi.example.com -Token your-existing-token -InstallPath C:\win-acme
   ```
   This overwrites the hook scripts in place with the renamed versions and re-registers
   the Scheduled Task under the new name (`Dashddi renewal (SYSTEM)`), removing the old
   `win-acme renewal (SYSTEM)` task.
2. Or, to move to the new default path entirely: copy `dashddi.ini` and the certificate
   store entries are unaffected (they live in the Windows Certificate Store, not the
   install directory) — just re-run the installer with a fresh `C:\dashddi` and your
   existing token.
