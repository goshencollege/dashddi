#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Install win-acme with DashDDI DNS validation on Windows.

.DESCRIPTION
    Downloads win-acme, installs it to C:\dashddi (configurable), writes a
    DashDDI credentials file, deploys the challenge hook scripts, and requests
    an initial certificate.

    A renewal wrapper (Update-DashddiCertificate.ps1) is registered as the daily
    Scheduled Task. It re-queries DashDDI for the current FQDN list on every
    run so the certificate's SAN list automatically stays in sync as records
    are added or removed - matching the Linux dashddi CLI's behaviour.

    Certificates are stored in the Windows Certificate Store (LocalMachine\My).

.PARAMETER Url
    Base URL of your DashDDI instance (e.g. https://dashddi.example.com).
    Prompted interactively if not provided.

.PARAMETER Token
    Host-scoped API token generated from the host detail page in DashDDI.
    Prompted interactively if not provided.

.PARAMETER Email
    Email address for the ACME account (used for expiry notifications).
    Prompted interactively if not provided.

.PARAMETER InstallPath
    Directory to install win-acme and the hook scripts. Default: C:\dashddi.

.PARAMETER Names
    Optional comma-separated explicit list of FQDNs to certify, replacing
    auto-discovery. Use this for a subset of the host's names, or to add
    concrete names covered by a wildcard record. Written to dashddi.ini as
    dns_dashddi_names.

.PARAMETER Caa
    Publish a CAA record authorizing Let's Encrypt at each issued FQDN after
    every successful (re)issuance. There's nothing to configure — the CA is
    fixed to Let's Encrypt, the only ACME server this installer supports.
    Written to dashddi.ini as dns_dashddi_caa = true.

.PARAMETER Https
    Publish an HTTPS (RFC 9460) record at each issued FQDN after every
    successful (re)issuance, using a default value ('1 . alpn=h2') unless
    -HttpsValue is also given. Written to dashddi.ini as dns_dashddi_https = true.

.PARAMETER HttpsValue
    Explicit HTTPS (RFC 9460) record value to create/update at each issued
    FQDN (implies -Https), e.g. '1 . alpn=h2,h3' if this server actually
    supports HTTP/3 (most don't without explicit QUIC configuration - the
    default omits h3 for that reason). Written to dashddi.ini as
    dns_dashddi_https_value.

.PARAMETER SkipCertRequest
    Deploy everything but skip the initial certificate request.

.PARAMETER NoScheduledTask
    Don't register the 'Dashddi renewal (SYSTEM)' Scheduled Task. By default it is
    always (re)registered, even when -SkipCertRequest is used, so that re-running the
    installer on an existing install picks up any renamed/updated scripts. Pass this
    switch to manage renewal scheduling yourself instead.

.EXAMPLE
    .\Install-Dashddi.ps1

.EXAMPLE
    .\Install-Dashddi.ps1 -Url https://dashddi.example.com -Token abc123 -Email admin@example.com
#>
[CmdletBinding()]
param(
    [string]$Url,
    [string]$Token,
    [string]$Email,
    [string]$InstallPath = 'C:\dashddi',
    [string]$Names,
    [switch]$Caa,
    [switch]$Https,
    [string]$HttpsValue,
    [switch]$SkipCertRequest,
    [switch]$NoScheduledTask
)

$ErrorActionPreference = 'Stop'

# ── 1. Credentials ────────────────────────────────────────────────────────────

if (-not $Url) {
    $Url = Read-Host 'DashDDI URL (e.g. https://dashddi.example.com)'
}
$Url = $Url.TrimEnd('/')

if (-not $Token) {
    $secure = Read-Host 'Host-scoped API token' -AsSecureString
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    try { $Token = [Runtime.InteropServices.Marshal]::PtrToStringAuto($bstr) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
}

if (-not $Email) {
    $Email = Read-Host 'Email address for ACME account'
}

# ── 2. Download win-acme ──────────────────────────────────────────────────────

if (-not (Test-Path (Join-Path $InstallPath 'wacs.exe'))) {
    Write-Host 'Downloading win-acme...'
    $release = Invoke-RestMethod -Uri 'https://api.github.com/repos/win-acme/win-acme/releases/latest'
    $asset = $release.assets | Where-Object { $_.name -like '*x64.trimmed.zip' } | Select-Object -First 1
    if (-not $asset) {
        Write-Error 'Could not find a win-acme x64 release asset. Check https://github.com/win-acme/win-acme/releases'
        exit 1
    }
    $zipPath = Join-Path $env:TEMP 'win-acme.zip'
    Invoke-WebRequest -Uri $asset.browser_download_url -OutFile $zipPath
    Write-Host "Installing to $InstallPath..."
    New-Item -ItemType Directory -Path $InstallPath -Force | Out-Null
    Expand-Archive -Path $zipPath -DestinationPath $InstallPath -Force
    Remove-Item $zipPath
} else {
    Write-Host "win-acme already present at $InstallPath."
}

# ── 3. Write settings.json ───────────────────────────────────────────────────
# Use public DNS resolvers for win-acme's pre-validation check. Without this,
# win-acme queries authoritative nameservers directly, which fails for domains
# hosted on internal/AD DNS servers where the challenge record is not present
# (it is published in the public parent zone by DashDDI instead).

$settingsPath = Join-Path $InstallPath 'settings.json'
if (-not (Test-Path $settingsPath)) {
    $settingsJson = '{
  "Validation": {
    "DnsServers": ["8.8.8.8", "1.1.1.1"]
  }
}'
    [System.IO.File]::WriteAllText($settingsPath, $settingsJson, [System.Text.Encoding]::ASCII)
    Write-Host "Settings written to $settingsPath"
} else {
    Write-Host "Settings file already exists at $settingsPath - skipping."
}

# ── 4. Deploy hook scripts ────────────────────────────────────────────────────

$baseRaw = 'https://raw.githubusercontent.com/goshencollege/dashddi/main/dashddi-client-windows'
$scriptDir = if ($PSScriptRoot) { $PSScriptRoot } else { $PWD.Path }

foreach ($script in 'New-DashddiChallenge.ps1', 'Remove-DashddiChallenge.ps1', 'Get-DashddiHosts.ps1', 'Publish-DashddiRecords.ps1', 'Update-DashddiCertificate.ps1') {
    $src = Join-Path $scriptDir $script
    if (-not (Test-Path $src)) {
        Write-Host "Downloading $script..."
        Invoke-WebRequest "$baseRaw/$script" -OutFile (Join-Path $InstallPath $script)
    } else {
        Copy-Item $src (Join-Path $InstallPath $script) -Force
    }
}

# ── 5. Credentials file ───────────────────────────────────────────────────────

$credPath = Join-Path $InstallPath 'dashddi.ini'
if (-not (Test-Path $credPath)) {
    $lines = @(
        "dns_dashddi_url = $Url"
        "dns_dashddi_token = $Token"
        "acme_email = $Email"
    )
    if ($Names)      { $lines += "dns_dashddi_names = $Names" }
    if ($Caa)        { $lines += "dns_dashddi_caa = true" }
    if ($HttpsValue) {
        $lines += "dns_dashddi_https_value = $HttpsValue"
    } elseif ($Https) {
        $lines += "dns_dashddi_https = true"
    }

    [System.IO.File]::WriteAllText($credPath, ($lines -join "`n") + "`n", [System.Text.Encoding]::ASCII)

    # Restrict ACL: SYSTEM + Administrators only, no inheritance
    $acl = Get-Acl $credPath
    $acl.SetAccessRuleProtection($true, $false)
    $acl.Access | ForEach-Object { $acl.RemoveAccessRule($_) | Out-Null }
    $acl.AddAccessRule((New-Object System.Security.AccessControl.FileSystemAccessRule(
        'NT AUTHORITY\SYSTEM', 'FullControl', 'Allow')))
    $acl.AddAccessRule((New-Object System.Security.AccessControl.FileSystemAccessRule(
        'BUILTIN\Administrators', 'FullControl', 'Allow')))
    Set-Acl $credPath $acl
    Write-Host "Credentials written to $credPath"
} else {
    Write-Host "Credentials file already exists at $credPath - skipping."
}

# ── 6. Discover FQDNs from DashDDI ───────────────────────────────────────────

if (-not $SkipCertRequest) {
    Write-Host 'Querying DashDDI for registered FQDNs...'
    $fqdns = @(& (Join-Path $InstallPath 'Get-DashddiHosts.ps1'))

    if ($fqdns.Count -eq 0) {
        Write-Error @"
No FQDNs to certify.
Check that:
  - The host has A, AAAA, or CNAME records linked to its interfaces.
  - Each domain has at least one view marked Public in DashDDI.
  - Or that dns_dashddi_names in dashddi.ini lists them explicitly.
"@
        exit 1
    }

    Write-Host "Found $($fqdns.Count) domain(s) for initial request: $($fqdns -join ', ')"

    # ── 7. Request certificate via win-acme ───────────────────────────────────

    $wacs   = Join-Path $InstallPath 'wacs.exe'
    $create = Join-Path $InstallPath 'New-DashddiChallenge.ps1'
    $delete = Join-Path $InstallPath 'Remove-DashddiChallenge.ps1'

    & $wacs `
        --source manual `
        --host ($fqdns -join ',') `
        --validationmode dns-01 `
        --validation script `
        --dnscreatescript $create `
        --dnscreatescriptarguments '{Identifier} {Token}' `
        --dnsdeletescript $delete `
        --dnsdeletescriptarguments '{Identifier} {Token}' `
        --store certificatestore `
        --accepttos `
        --emailaddress $Email `
        --closeonfinish

    if ($LASTEXITCODE -ne 0) {
        Write-Error "win-acme exited with code $LASTEXITCODE"
        exit $LASTEXITCODE
    }

    & (Join-Path $InstallPath 'Publish-DashddiRecords.ps1') -Fqdns $fqdns
}

# ── 8. Replace win-acme's renewal task with our FQDN-aware wrapper ────────────
# win-acme registers a task that calls 'wacs.exe --renew' with the static
# host list from the initial install. Replace it with Update-DashddiCertificate.ps1
# which re-queries DashDDI on every run so the SAN list stays current.
#
# This runs even with -SkipCertRequest ("deploy everything but skip the initial
# certificate request") so re-running the installer to pick up renamed scripts
# on an existing install re-registers the task without forcing a cert request.
# Pass -NoScheduledTask to opt out and manage renewal scheduling yourself.

$taskName = 'Dashddi renewal (SYSTEM)'

if (-not $NoScheduledTask) {
    $renewScript = Join-Path $InstallPath 'Update-DashddiCertificate.ps1'
    $taskAction  = New-ScheduledTaskAction `
        -Execute 'powershell.exe' `
        -Argument "-NonInteractive -ExecutionPolicy Bypass -File `"$renewScript`""
    $taskTrigger  = New-ScheduledTaskTrigger -Daily -At '09:00AM'
    $taskSettings = New-ScheduledTaskSettingsSet `
        -ExecutionTimeLimit (New-TimeSpan -Hours 2) `
        -MultipleInstances IgnoreNew
    $taskPrincipal = New-ScheduledTaskPrincipal `
        -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest

    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction SilentlyContinue
    Register-ScheduledTask -TaskName $taskName `
        -Action $taskAction -Trigger $taskTrigger `
        -Settings $taskSettings -Principal $taskPrincipal -Force | Out-Null
    Write-Host "Scheduled Task '$taskName' configured for DashDDI FQDN-aware renewal."
} else {
    Write-Host "Skipping Scheduled Task setup (-NoScheduledTask). Renewal will not run automatically."
}

Write-Host ''
Write-Host 'Installation complete.'
Write-Host "  win-acme:       $InstallPath"
Write-Host "  Credentials:    $credPath"
Write-Host "  Certificate:    Windows Certificate Store (LocalMachine\My)"
if (-not $NoScheduledTask) {
    Write-Host "  Renewal:        Scheduled Task '$taskName' -> Update-DashddiCertificate.ps1"
    Write-Host ''
    Write-Host 'To trigger a manual renewal:'
    Write-Host "  & `"$InstallPath\wacs.exe`" --renew --force"
    Write-Host ''
    Write-Host 'FQDNs are re-queried from DashDDI on every renewal - the SAN list updates'
    Write-Host 'automatically as records are added or removed. No re-installation needed.'
} else {
    Write-Host '  Renewal:        not scheduled (-NoScheduledTask)'
    Write-Host ''
    Write-Host 'To renew manually:'
    Write-Host "  & `"$InstallPath\Update-DashddiCertificate.ps1`""
}
