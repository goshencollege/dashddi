#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Install win-acme with DashDDI DNS validation on Windows.

.DESCRIPTION
    Downloads win-acme, installs it to C:\win-acme (configurable), writes a
    DashDDI credentials file, deploys the challenge hook scripts, and requests
    an initial certificate.

    FQDNs are discovered from DashDDI on every renewal run via Get-Hosts.ps1,
    so the certificate's SAN list automatically stays in sync as DNS records
    are added or removed in DashDDI — no re-installation required.

    Certificates are stored in the Windows Certificate Store (LocalMachine\My).
    win-acme automatically creates a daily Scheduled Task for renewal.

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

# ── 3. Deploy hook scripts ────────────────────────────────────────────────────

$baseRaw = 'https://raw.githubusercontent.com/goshencollege/dashddi/main/win-acme-dashddi'
$scriptDir = if ($PSScriptRoot) { $PSScriptRoot } else { $PWD.Path }

foreach ($script in 'Create-AcmeChallenge.ps1', 'Delete-AcmeChallenge.ps1', 'Get-Hosts.ps1') {
    $src = Join-Path $scriptDir $script
    if (-not (Test-Path $src)) {
        Write-Host "Downloading $script..."
        Invoke-WebRequest "$baseRaw/$script" -OutFile (Join-Path $InstallPath $script)
    } else {
        Copy-Item $src (Join-Path $InstallPath $script) -Force
    }
}

# ── 4. Credentials file ───────────────────────────────────────────────────────

$credPath = Join-Path $InstallPath 'dashddi.ini'
if (-not (Test-Path $credPath)) {
    [System.IO.File]::WriteAllText(
        $credPath,
        "dns_dashddi_url = $Url`ndns_dashddi_token = $Token`n",
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

# ── 5. Discover FQDNs from DashDDI ───────────────────────────────────────────

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

    # ── 6. Request certificate via win-acme ───────────────────────────────────

    $wacs     = Join-Path $InstallPath 'wacs.exe'
    $create   = Join-Path $InstallPath 'Create-AcmeChallenge.ps1'
    $delete   = Join-Path $InstallPath 'Delete-AcmeChallenge.ps1'
    $getHosts = Join-Path $InstallPath 'Get-Hosts.ps1'

    & $wacs `
        --source script `
        --hostsscript $getHosts `
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
Write-Host 'FQDNs are re-queried from DashDDI on every renewal — the SAN list updates'
Write-Host 'automatically as records are added or removed. No re-installation needed.'
