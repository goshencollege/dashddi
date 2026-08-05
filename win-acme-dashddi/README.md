# win-acme-dashddi

Integrates [win-acme](https://www.win-acme.com/) with [DashDDI](https://github.com/goshencollege/dashddi) to issue and automatically renew Let's Encrypt certificates on Windows hosts. Certificates are stored in the **Windows Certificate Store** (LocalMachine\My), making them natively available to IIS, WCF, and other Windows services.

DNS-01 challenge records are created and removed via the DashDDI host self-service API using a host-scoped token, so no broad DNS credentials are required.

## Requirements

- Windows Server 2016 / Windows 10 or later
- PowerShell 5.1 or later (built into all supported Windows versions)
- The host must be registered in DashDDI with at least one network interface whose IP address matches the machine running this script
- The host's A, AAAA, or CNAME record must exist in DashDDI in a domain with at least one **public** DNS view (or a public parent domain)
- A host-scoped token generated from the host detail page in DashDDI

## Setup

### 1. Generate a host-scoped token

In the DashDDI UI, navigate to **Hosts**, open the host record for this machine, and click **Generate Token** in the Host API Token card. Copy the displayed token — it is shown only once.

### 2. Run the installer

From an **elevated** PowerShell prompt:

```powershell
# Download and inspect first (recommended):
Invoke-WebRequest -Uri https://raw.githubusercontent.com/goshencollege/dashddi/main/win-acme-dashddi/Install-DashddiWinAcme.ps1 -OutFile Install-DashddiWinAcme.ps1
.\Install-DashddiWinAcme.ps1
```

The installer prompts for your DashDDI URL, host-scoped token, and an email address for ACME account notifications. Pass them directly for unattended use:

```powershell
.\Install-DashddiWinAcme.ps1 `
    -Url https://dashddi.example.com `
    -Token your-host-scoped-token `
    -Email admin@example.com
```

### What gets installed

| Path / Item | Purpose |
|---|---|
| `C:\win-acme\wacs.exe` | win-acme ACME client |
| `C:\win-acme\Renew-DashddiWinAcme.ps1` | Daily renewal wrapper: re-queries DashDDI then calls wacs.exe |
| `C:\win-acme\Get-Hosts.ps1` | Called by the renewal wrapper to discover current FQDNs from DashDDI |
| `C:\win-acme\Create-AcmeChallenge.ps1` | Hook called by win-acme to create TXT records via DashDDI |
| `C:\win-acme\Delete-AcmeChallenge.ps1` | Hook called by win-acme to remove TXT records after validation |
| `C:\win-acme\dashddi.ini` | Credentials file (ACL restricted to SYSTEM + Administrators) |
| Windows Certificate Store → `LocalMachine\My` | Issued certificate |
| Task Scheduler → `win-acme renewal (SYSTEM)` | Daily task running `Renew-DashddiWinAcme.ps1` as SYSTEM |

## How it works

The installer registers `Renew-DashddiWinAcme.ps1` as the `win-acme renewal (SYSTEM)` Scheduled Task. On every daily run (and on the initial install) the wrapper re-queries DashDDI for the current FQDN list before calling wacs.exe, so the certificate's SAN list stays in sync as DNS records are added or removed — matching the behaviour of `dashddi-certbot` on Linux.

1. `Renew-DashddiWinAcme.ps1` calls `Get-Hosts.ps1`, which queries `GET /api/self/host` and returns the current A/AAAA/CNAME FQDNs registered to this host in DashDDI.
2. The wrapper calls wacs.exe with the current FQDN list. win-acme renews the certificate if it is within its renewal window; otherwise it exits cleanly.
3. For each domain that needs validation, Let's Encrypt asks win-acme to prove control via a DNS-01 challenge. win-acme calls `Create-AcmeChallenge.ps1`, which posts to `POST /api/self/dns-challenge` with the FQDN and validation token.
4. DashDDI creates the `_acme-challenge.*` TXT record in the appropriate public DNS view (or a multipart label in a public parent domain if the host's domain is managed externally, e.g. Active Directory).
5. After validation, win-acme calls `Delete-AcmeChallenge.ps1`, which posts to `DELETE /api/self/dns-challenge`.
6. The issued certificate is installed into the Windows Certificate Store under `LocalMachine\My`.

## Operations

**Force an immediate renewal:**

```powershell
& "C:\win-acme\wacs.exe" --renew --force
```

**List managed renewals:**

```powershell
& "C:\win-acme\wacs.exe" --list
```

**Adding or removing DNS records in DashDDI:**

No action required. `Get-Hosts.ps1` re-queries DashDDI on every renewal, so the certificate's SAN list updates automatically on the next daily renewal run. To pick up a change immediately, force a renewal:

```powershell
& "C:\win-acme\wacs.exe" --renew --force
```

## Troubleshooting

**`401 Unauthorized`**

The token is invalid or the request is coming from an IP not assigned to the host in DashDDI. Verify the token and that the host's interfaces include the machine's current IP.

**`403 Forbidden` — "FQDN does not belong to this host"**

The FQDN has no DNS record linked to this host's interfaces in DashDDI. Add an A or AAAA record before running the installer.

**`422 Unprocessable Entity` — "no public views"**

The domain has no public view and no public ancestor domain in DashDDI. Mark a view as Public, or ensure a public parent domain exists.

**No FQDNs discovered**

The installer found no A/AAAA/CNAME records reachable from the internet for this host. Check that the host has DNS records in DashDDI and that the domain (or a parent domain) has a public view.
