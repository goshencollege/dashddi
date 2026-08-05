# Called by the 'win-acme renewal (SYSTEM)' Scheduled Task on each renewal run.
# Re-queries DashDDI for the current FQDN list before every renewal so the
# certificate's SAN list stays in sync as DNS records are added or removed --
# matching the behaviour of dashddi-certbot on Linux.

$ErrorActionPreference = 'Stop'

$installPath = $PSScriptRoot

$cfg = Get-Content (Join-Path $installPath 'dashddi.ini') -Raw | ConvertFrom-StringData
$email = $cfg.acme_email

# Re-discover FQDNs from DashDDI on every run
$fqdns = @(& (Join-Path $installPath 'Get-Hosts.ps1'))

if ($fqdns.Count -eq 0) {
    Write-Warning 'No FQDNs found in DashDDI - skipping renewal'
    exit 0
}

Write-Host "Renewing certificate for $($fqdns.Count) domain(s): $($fqdns -join ', ')"

$wacs   = Join-Path $installPath 'wacs.exe'
$create = Join-Path $installPath 'Create-AcmeChallenge.ps1'
$delete = Join-Path $installPath 'Delete-AcmeChallenge.ps1'

$wacsArgs = @(
    '--source', 'manual',
    '--host', ($fqdns -join ','),
    '--validationmode', 'dns-01',
    '--validation', 'script',
    '--dnscreatescript', $create,
    '--dnscreatescriptarguments', '{Identifier} {Token}',
    '--dnsdeletescript', $delete,
    '--dnsdeletescriptarguments', '{Identifier} {Token}',
    '--store', 'certificatestore',
    '--accepttos',
    '--closeonfinish'
)
if ($email) { $wacsArgs += '--emailaddress', $email }

& $wacs @wacsArgs

if ($LASTEXITCODE -ne 0) {
    Write-Error "win-acme exited with code $LASTEXITCODE"
    exit $LASTEXITCODE
}
