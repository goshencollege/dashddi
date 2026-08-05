#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Install certbot-dns-dashddi and configure automatic certificate renewal on Windows.

.DESCRIPTION
    Creates a Python virtualenv at C:\Certbot (configurable), installs Certbot and
    the certbot-dns-dashddi plugin, writes a credentials file, requests an initial
    certificate via dashddi-certbot, and registers a Windows Scheduled Task that
    runs dashddi-certbot daily to keep the certificate renewed and in sync with
    the FQDNs registered for this host in DashDDI.

.PARAMETER Url
    Base URL of your DashDDI instance (e.g. https://dashddi.example.com).
    Prompted interactively if not provided.

.PARAMETER Token
    Host-scoped API token generated from the host detail page in DashDDI.
    Prompted interactively if not provided.

.PARAMETER VenvPath
    Path for the Python virtualenv. Default: C:\Certbot.

.PARAMETER CredentialsPath
    Path for the DashDDI credentials file. Default: C:\Certbot\dashddi.ini.

.PARAMETER PropagationSeconds
    Seconds to wait for DNS propagation before asking the CA to validate.
    Default: 30.

.PARAMETER SkipCertRequest
    Skip the initial certificate request (useful for testing the setup steps).

.EXAMPLE
    .\Install-DashddiCertbot.ps1

.EXAMPLE
    .\Install-DashddiCertbot.ps1 -Url https://dashddi.example.com -Token abc123
#>
[CmdletBinding()]
param(
    [string]$Url,
    [string]$Token,
    [string]$VenvPath = 'C:\Certbot',
    [string]$CredentialsPath,
    [int]$PropagationSeconds = 30,
    [switch]$SkipCertRequest
)

$ErrorActionPreference = 'Stop'

if (-not $CredentialsPath) {
    $CredentialsPath = Join-Path $VenvPath 'dashddi.ini'
}

# ── 1. Preflight: Python ──────────────────────────────────────────────────────

$python = Get-Command python -ErrorAction SilentlyContinue
if (-not $python) {
    Write-Error @"
Python 3.9 or higher is required but was not found on PATH.
Download and install it from https://www.python.org/downloads/
Be sure to check "Add python.exe to PATH" during setup, then re-run this script.
"@
    exit 1
}

$pyVersionString = & python -c "import sys; print(f'{sys.version_info.major}.{sys.version_info.minor}')"
$parts = $pyVersionString.Split('.')
$pyMajor = [int]$parts[0]
$pyMinor = [int]$parts[1]
if ($pyMajor -lt 3 -or ($pyMajor -eq 3 -and $pyMinor -lt 9)) {
    Write-Error "Python 3.9 or higher is required. Found: $pyVersionString"
    exit 1
}
Write-Host "Python $pyVersionString detected."

# ── 2. Create virtualenv ──────────────────────────────────────────────────────

if (-not (Test-Path (Join-Path $VenvPath 'Scripts\python.exe'))) {
    Write-Host "Creating virtualenv at $VenvPath..."
    & python -m venv $VenvPath
} else {
    Write-Host "Virtualenv already exists at $VenvPath."
}

$pip        = Join-Path $VenvPath 'Scripts\pip.exe'
$certbot    = Join-Path $VenvPath 'Scripts\certbot.exe'
$dashddiCertbot = Join-Path $VenvPath 'Scripts\dashddi-certbot.exe'

# ── 3. Install packages ───────────────────────────────────────────────────────

Write-Host "Installing certbot..."
& $pip install --quiet --upgrade certbot

Write-Host "Installing certbot-dns-dashddi plugin..."
& $pip install --quiet --upgrade "git+https://github.com/goshencollege/dashddi/#subdirectory=certbot-dns-dashddi"

Write-Host "Verifying plugin is detected by certbot..."
& $certbot plugins | Select-String 'dns-dashddi'

# ── 4. Credentials file ───────────────────────────────────────────────────────

if (-not (Test-Path $CredentialsPath)) {
    if (-not $Url) {
        $Url = Read-Host 'DashDDI URL (e.g. https://dashddi.example.com)'
    }
    if (-not $Token) {
        $secureToken = Read-Host 'Host-scoped API token' -AsSecureString
        $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureToken)
        try { $Token = [Runtime.InteropServices.Marshal]::PtrToStringAuto($bstr) }
        finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
    }

    $credDir = Split-Path $CredentialsPath
    if ($credDir -and -not (Test-Path $credDir)) {
        New-Item -ItemType Directory -Path $credDir | Out-Null
    }

    @"
dns_dashddi_url = $Url
dns_dashddi_token = $Token
"@ | Set-Content -Path $CredentialsPath -Encoding UTF8

    # Restrict ACL: SYSTEM + Administrators only, no inheritance.
    $acl = Get-Acl $CredentialsPath
    $acl.SetAccessRuleProtection($true, $false)
    $acl.Access | ForEach-Object { $acl.RemoveAccessRule($_) | Out-Null }
    $acl.AddAccessRule((New-Object System.Security.AccessControl.FileSystemAccessRule(
        'NT AUTHORITY\SYSTEM', 'FullControl', 'Allow')))
    $acl.AddAccessRule((New-Object System.Security.AccessControl.FileSystemAccessRule(
        'BUILTIN\Administrators', 'FullControl', 'Allow')))
    Set-Acl $CredentialsPath $acl

    Write-Host "Credentials written to $CredentialsPath"
} else {
    Write-Host "Credentials file already exists at $CredentialsPath - skipping."
}

# ── 5. Write renewal wrapper ──────────────────────────────────────────────────

$renewScript = Join-Path $VenvPath 'Renew-DashddiCertbot.ps1'
@"
# Activate the certbot virtualenv and run dashddi-certbot to renew certificates,
# updating the SAN list to match the current FQDNs registered in DashDDI.
& "$VenvPath\Scripts\Activate.ps1"
dashddi-certbot --credentials "$CredentialsPath"
"@ | Set-Content -Path $renewScript -Encoding UTF8
Write-Host "Renewal wrapper written to $renewScript"

# ── 6. Request initial certificate ───────────────────────────────────────────

if (-not $SkipCertRequest) {
    Write-Host "Requesting certificate (this may take a moment for DNS propagation)..."
    & $dashddiCertbot --credentials $CredentialsPath -- --dns-dashddi-propagation-seconds $PropagationSeconds
} else {
    Write-Host "Skipping initial certificate request (-SkipCertRequest was set)."
}

# ── 7. Scheduled Task ─────────────────────────────────────────────────────────

Write-Host "Registering Scheduled Task 'DashddiCertbotRenew'..."

$action = New-ScheduledTaskAction `
    -Execute 'powershell.exe' `
    -Argument "-NonInteractive -File `"$renewScript`""

$trigger = New-ScheduledTaskTrigger -Daily -At '03:00'

$principal = New-ScheduledTaskPrincipal `
    -UserId 'NT AUTHORITY\SYSTEM' `
    -RunLevel Highest `
    -LogonType ServiceAccount

$settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -ExecutionTimeLimit (New-TimeSpan -Hours 1) `
    -MultipleInstances IgnoreNew

Register-ScheduledTask `
    -TaskName 'DashddiCertbotRenew' `
    -Action $action `
    -Trigger $trigger `
    -Principal $principal `
    -Settings $settings `
    -Description 'Renew TLS certificates via DashDDI and update SANs to match registered FQDNs' `
    -Force | Out-Null

Write-Host ''
Write-Host 'Installation complete.'
Write-Host "  Virtualenv:      $VenvPath"
Write-Host "  Credentials:     $CredentialsPath"
Write-Host "  Renewal script:  $renewScript"
Write-Host "  Scheduled task:  DashddiCertbotRenew (runs daily at 03:00)"
Write-Host ''
Write-Host 'To run the renewal task manually:'
Write-Host "  powershell -NonInteractive -File `"$renewScript`""
Write-Host 'Or trigger via Task Scheduler:'
Write-Host '  Start-ScheduledTask -TaskName DashddiCertbotRenew'
