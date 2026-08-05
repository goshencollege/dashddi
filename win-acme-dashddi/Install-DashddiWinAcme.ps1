#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Install win-acme with DashDDI DNS validation on Windows.

.DESCRIPTION
    Downloads win-acme, installs it to C:\win-acme (configurable), writes a
    DashDDI credentials file, deploys the challenge hook scripts, and requests
    an initial certificate.

    A renewal wrapper (Renew-DashddiWinAcme.ps1) is registered as the daily
    Scheduled Task. It re-queries DashDDI for the current FQDN list on every
    run so the certificate's SAN list automatically stays in sync as records
    are added or removed - matching the Linux dashddi-certbot behaviour.

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
    Directory to install win-acme and the hook scripts. Default: C:\win-acme.

.PARAMETER SkipCertRequest
    Deploy everything but skip the initial certificate request.

.EXAMPLE
    .\Install-DashddiWinAcme.ps1

.EXAMPLE
    .\Install-DashddiWinAcme.ps1 -Url https://dashddi.example.com -Token abc123 -Email admin@example.com
#>
[CmdletBinding()]
param(
    [string]$Url,
    [string]$Token,
    [string]$Email,
    [string]$InstallPath = 'C:\win-acme',
    [switch]$SkipCertRequest
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

$baseRaw = 'https://raw.githubusercontent.com/goshencollege/dashddi/main/win-acme-dashddi'
$scriptDir = if ($PSScriptRoot) { $PSScriptRoot } else { $PWD.Path }

foreach ($script in 'Create-AcmeChallenge.ps1', 'Delete-AcmeChallenge.ps1', 'Get-Hosts.ps1', 'Renew-DashddiWinAcme.ps1') {
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
    [System.IO.File]::WriteAllText(
        $credPath,
        "dns_dashddi_url = $Url`ndns_dashddi_token = $Token`nacme_email = $Email`n",
        [System.Text.Encoding]::ASCII
    )

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
    try {
        $hostData = Invoke-RestMethod `
            -Uri "$Url/api/self/host" `
            -Headers @{ Authorization = "Bearer $Token" }
    } catch {
        Write-Error "Failed to contact DashDDI at ${Url}: $_"
        exit 1
    }

    $fqdns = @()
    foreach ($iface in $hostData.interfaces) {
        foreach ($record in $iface.records) {
            if ($record.type -in 'A', 'AAAA', 'CNAME' -and $record.fqdn -and $record.fqdn -notin $fqdns) {
                $fqdns += $record.fqdn
            }
        }
    }

    if ($fqdns.Count -eq 0) {
        Write-Error @"
No publicly-reachable A/AAAA/CNAME FQDNs found for this host in DashDDI.
Check that:
  - The host has A, AAAA, or CNAME records linked to its interfaces.
  - Each domain has at least one view marked Public in DashDDI.
"@
        exit 1
    }

    Write-Host "Found $($fqdns.Count) domain(s) for initial request: $($fqdns -join ', ')"

    # ── 7. Request certificate via win-acme ───────────────────────────────────

    $wacs   = Join-Path $InstallPath 'wacs.exe'
    $create = Join-Path $InstallPath 'Create-AcmeChallenge.ps1'
    $delete = Join-Path $InstallPath 'Delete-AcmeChallenge.ps1'

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

    # ── 8. Replace win-acme's renewal task with our FQDN-aware wrapper ────────
    # win-acme registers a task that calls 'wacs.exe --renew' with the static
    # host list from the initial install. Replace it with Renew-DashddiWinAcme.ps1
    # which re-queries DashDDI on every run so the SAN list stays current.

    $taskName   = 'win-acme renewal (SYSTEM)'
    $renewScript = Join-Path $InstallPath 'Renew-DashddiWinAcme.ps1'
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
}

Write-Host ''
Write-Host 'Installation complete.'
Write-Host "  win-acme:       $InstallPath"
Write-Host "  Credentials:    $credPath"
Write-Host "  Certificate:    Windows Certificate Store (LocalMachine\My)"
Write-Host "  Renewal:        Scheduled Task created automatically by win-acme"
Write-Host ''
Write-Host 'To trigger a manual renewal:'
Write-Host "  & `"$InstallPath\wacs.exe`" --renew --force"
Write-Host ''
Write-Host "  Renewal task:   'win-acme renewal (SYSTEM)' -> Renew-DashddiWinAcme.ps1"
Write-Host ''
Write-Host 'FQDNs are re-queried from DashDDI on every renewal - the SAN list updates'
Write-Host 'automatically as records are added or removed. No re-installation needed.'
