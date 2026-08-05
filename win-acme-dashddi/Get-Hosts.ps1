# Called by win-acme's Script source plugin on each renewal to discover
# the current set of FQDNs for this host from DashDDI.
# Outputs one FQDN per line to stdout.
#
# win-acme re-runs this script on every renewal, so the certificate's SAN
# list automatically stays in sync with DNS records in DashDDI.

param()

$ErrorActionPreference = 'Stop'

$cfg = Get-Content (Join-Path $PSScriptRoot 'dashddi.ini') -Raw | ConvertFrom-StringData
$baseUrl = $cfg.dns_dashddi_url.TrimEnd('/')
$apiToken = $cfg.dns_dashddi_token

if (-not $baseUrl -or -not $apiToken) {
    Write-Error 'dashddi.ini must contain dns_dashddi_url and dns_dashddi_token'
    exit 1
}

try {
    $hostData = Invoke-RestMethod -Uri "$baseUrl/api/self/host" `
        -Headers @{ Authorization = "Bearer $apiToken" }
} catch {
    Write-Error "Failed to contact DashDDI at ${baseUrl}: $_"
    exit 1
}

$seen = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::OrdinalIgnoreCase)
foreach ($iface in $hostData.interfaces) {
    foreach ($record in $iface.records) {
        if ($record.type -in 'A', 'AAAA', 'CNAME' -and $record.fqdn -and $seen.Add($record.fqdn)) {
            Write-Output $record.fqdn
        }
    }
}
